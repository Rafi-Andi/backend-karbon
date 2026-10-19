/**
 * Server lokal yang menjalankan handler Worker apa adanya (untuk uji E2E
 * sebelum deploy ke Cloudflare). Jalankan: node relay.dev-server.mjs
 */
import { createServer } from 'node:http';
import worker from './worker.js';

const PORT = Number(process.env.PORT || 8799);
const RELAY_KEY = process.env.RELAY_KEY || 'rahasia-lokal';

const server = createServer(async (req, res) => {
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const body = Buffer.concat(chunks);

  const url = `http://127.0.0.1:${PORT}${req.url}`;
  const request = new Request(url, {
    method: req.method,
    headers: req.headers,
    body: req.method === 'POST' ? body : undefined,
  });

  const response = await worker.fetch(request, { RELAY_KEY });
  res.writeHead(response.status, Object.fromEntries(response.headers));
  res.end(Buffer.from(await response.arrayBuffer()));
});

server.listen(PORT, () => {
  console.log(`relay lokal: http://127.0.0.1:${PORT}  (RELAY_KEY=${RELAY_KEY})`);
});
