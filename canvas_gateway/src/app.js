import { createServer } from 'node:http';
import { timingSafeEqual } from 'node:crypto';

class ApiError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}
const id = value => Number.isSafeInteger(value) && value > 0;

export function createApp(config, fetchImpl = fetch) {
  const vault = new URL(config.vaultUrl);
  const canvas = new URL(config.canvasUrl);
  for (const url of [vault, canvas]) {
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) {
      throw new Error('Base URLs must be HTTP(S), without credentials, query or fragment.');
    }
  }
  if (!config.gatewayKey || !config.canvasToken) throw new Error('Gateway and Canvas credentials are required.');
  for (const mapping of Object.values(config.mappings)) {
    if (!id(mapping.course_id) || !id(mapping.assignment_id)) throw new Error('Invalid assignment mapping.');
  }
  for (const userId of Object.values(config.users)) {
    if (!id(userId)) throw new Error('Invalid Canvas user mapping.');
  }
  async function upstream(url, options, name) {
    try {
      const response = await fetchImpl(url, { ...options, redirect: 'error', signal: AbortSignal.timeout(10000) });
      if (!response.ok) {
        const status = name === 'VAuLT' && [401, 403, 404].includes(response.status) ? response.status : 502;
        throw new ApiError(status, `${name} request failed (HTTP ${response.status}).`);
      }
      return await response.json();
    } catch (error) {
      if (error instanceof ApiError) throw error;
      throw new ApiError(502, `${name} unavailable or returned invalid JSON.`);
    }
  }
  return createServer(async (req, res) => {
    const send = (status, body) => {
      res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify(body));
    };
    try {
      if (req.url === '/health' && req.method === 'GET') return send(200, { status: 'ok' });
      if (req.url !== '/api/v1/grades/sync') throw new ApiError(404, 'Route not found.');
      if (req.method !== 'POST') { res.setHeader('Allow', 'POST'); throw new ApiError(405, 'POST required.'); }
      const supplied = Buffer.from(req.headers.authorization || '');
      const expected = Buffer.from(`Bearer ${config.gatewayKey}`);
      if (supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) throw new ApiError(401, 'Invalid gateway credential.');
      if (!req.headers['content-type']?.split(';')[0].trim().match(/^application\/json$/i)) throw new ApiError(415, 'application/json required.');
      let raw = '';
      let size = 0;
      for await (const chunk of req) {
        size += chunk.length;
        if (size > 16384) throw new ApiError(413, 'Request too large.');
        raw += chunk.toString();
      }
      let body;
      try { body = JSON.parse(raw); } catch { throw new ApiError(400, 'Invalid JSON.'); }
      if (!body || !id(body.quest_id) || typeof body.vault_access_token !== 'string' ||
          !body.vault_access_token || /\s/.test(body.vault_access_token) ||
          Object.keys(body).some(key => !['quest_id', 'vault_access_token'].includes(key))) {
        throw new ApiError(400, 'Expected quest_id and vault_access_token only.');
      }
      const mapping = config.mappings[String(body.quest_id)];
      if (!mapping) throw new ApiError(422, 'Quest has no Canvas assignment mapping.');
      const result = await upstream(new URL(`api/v1/quests/${body.quest_id}/progress`, vault.href.replace(/\/?$/, '/')), {
        headers: { Authorization: `Bearer ${body.vault_access_token}` },
      }, 'VAuLT');
      const progress = result.data;
      if (!progress || !id(progress.user_id) || progress.quest_id !== body.quest_id ||
          !id(progress.total_tasks) || !Number.isInteger(progress.completed_tasks) ||
          progress.completed_tasks < 0 || progress.completed_tasks > progress.total_tasks) {
        throw new ApiError(422, 'VAuLT progress is invalid or quest has no active tasks.');
      }
      const canvasUserId = config.users[String(progress.user_id)];
      if (!canvasUserId) throw new ApiError(422, 'VAuLT user has no Canvas user mapping.');
      const percent = Math.round(progress.completed_tasks / progress.total_tasks * 10000) / 100;
      const submission = await upstream(new URL(`api/v1/courses/${mapping.course_id}/assignments/${mapping.assignment_id}/submissions/${canvasUserId}`, canvas.href.replace(/\/?$/, '/')), {
        method: 'PUT', headers: { Authorization: `Bearer ${config.canvasToken}`, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 'submission[posted_grade]': `${percent}%` }).toString(),
      }, 'Canvas');
      send(200, { data: { quest_id: body.quest_id, progress_percent: percent,
        canvas: { assignment_id: mapping.assignment_id, user_id: canvasUserId, score: submission.score, grade: submission.grade } } });
    } catch (error) {
      send(error instanceof ApiError ? error.status : 500, { error: error instanceof ApiError ? error.message : 'Internal server error.' });
    }
  });
}
