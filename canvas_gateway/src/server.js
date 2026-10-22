import { readFileSync } from 'node:fs';
import { createApp } from './app.js';

const config = {
  vaultUrl: process.env.VAULT_BASE_URL,
  canvasUrl: process.env.CANVAS_BASE_URL,
  canvasToken: process.env.CANVAS_ACCESS_TOKEN,
  gatewayKey: process.env.GATEWAY_API_KEY,
  ...JSON.parse(readFileSync(process.env.MAPPINGS_FILE || './mappings.json', 'utf8')),
};
const port = Number(process.env.PORT || 3001);
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Invalid PORT.');
createApp(config).listen(port, process.env.HOST || '127.0.0.1', () => console.log(`Canvas gateway listening on port ${port}`));
