import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApp } from '../src/app.js';

const config = { vaultUrl: 'http://vault.test', canvasUrl: 'https://canvas.test',
  gatewayKey: 'test-secret', canvasToken: 'canvas-secret',
  mappings: { '1': { course_id: 10, assignment_id: 20 } }, users: { '7': 30 } };

async function run(t, upstream, body, headers = {}) {
  const app = createApp(config, upstream);
  await new Promise(resolve => app.listen(0, '127.0.0.1', resolve));
  t.after(() => new Promise(resolve => { app.close(resolve); app.closeAllConnections(); }));
  return fetch(`http://127.0.0.1:${app.address().port}/api/v1/grades/sync`, {
    method: 'POST', headers: { Authorization: 'Bearer test-secret', 'Content-Type': 'application/json', ...headers },
    body: JSON.stringify(body),
  });
}
const body = { quest_id: 1, vault_access_token: 'vault-secret' };
const progress = { data: { user_id: 7, quest_id: 1, total_tasks: 4, completed_tasks: 3 } };

test('reads authenticated progress and PUTs percentage to mapped Canvas student', async t => {
  const calls = [];
  const response = await run(t, async (url, options) => {
    calls.push({ url: String(url), options });
    return Response.json(calls.length === 1 ? progress : { score: 7.5, grade: '75%' });
  }, body);
  assert.equal(response.status, 200);
  assert.equal((await response.json()).data.progress_percent, 75);
  assert.equal(calls[0].options.headers.Authorization, 'Bearer vault-secret');
  assert.equal(calls[0].url, 'http://vault.test/api/v1/quests/1/progress');
  assert.equal(calls[1].url, 'https://canvas.test/api/v1/courses/10/assignments/20/submissions/30');
  assert.equal(calls[1].options.method, 'PUT');
  assert.equal(new URLSearchParams(calls[1].options.body).get('submission[posted_grade]'), '75%');
});
test('rejects gateway authentication before upstream calls', async t => {
  const response = await run(t, () => assert.fail('must not call upstream'), body, { Authorization: 'Bearer wrong' });
  assert.equal(response.status, 401);
});
test('rejects caller-provided grade or Canvas identity', async t => {
  const response = await run(t, () => assert.fail('must not call upstream'), { ...body, posted_grade: 100, user_id: 99 });
  assert.equal(response.status, 400);
});
test('does not grade an empty quest', async t => {
  let calls = 0;
  const response = await run(t, async () => { calls++; return Response.json({ data: { ...progress.data, total_tasks: 0, completed_tasks: 0 } }); }, body);
  assert.equal(response.status, 422);
  assert.equal(calls, 1);
});
test('does not grade unmapped students', async t => {
  let calls = 0;
  const response = await run(t, async () => { calls++; return Response.json({ data: { ...progress.data, user_id: 99 } }); }, body);
  assert.equal(response.status, 422);
  assert.equal(calls, 1);
});
test('propagates VAuLT authentication failure without exposing tokens', async t => {
  const response = await run(t, async () => new Response('', { status: 401 }), body);
  assert.equal(response.status, 401);
  assert.deepEqual(await response.json(), { error: 'VAuLT request failed (HTTP 401).' });
});
test('Canvas errors are reported as upstream failures', async t => {
  let calls = 0;
  const response = await run(t, async () => ++calls === 1 ? Response.json(progress) : new Response('secret upstream response', { status: 403 }), body);
  assert.equal(response.status, 502);
  assert.deepEqual(await response.json(), { error: 'Canvas request failed (HTTP 403).' });
});
test('network timeout returns a gateway error', async t => {
  const response = await run(t, async () => { throw new Error('token-sensitive details'); }, body);
  assert.equal(response.status, 502);
  assert.deepEqual(await response.json(), { error: 'VAuLT unavailable or returned invalid JSON.' });
});
