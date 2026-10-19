/**
 * Uji relay tanpa deploy: panggil handler Worker langsung dengan mock fetch.
 * Jalankan: node relay.test.mjs
 */
import worker from './worker.js';

const calls = [];

globalThis.fetch = async (url, init) => {
  calls.push({ url, init });
  return new Response(JSON.stringify({ candidates: [{ content: { parts: [{ text: 'ok' }] } }] }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
};

const env = { RELAY_KEY: 'rahasia-test' };

function req(path, key, method = 'POST', body = '{"x":1}') {
  const headers = {};
  if (key !== null) headers['X-Relay-Key'] = key;
  return new Request(`https://relay.contoh.id${path}`, { method, headers, body: method === 'POST' ? body : undefined });
}

const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

// 1. tanpa key
let r = await worker.fetch(req('/v1beta/models/gemini-3.5-flash-lite:generateContent', null), env);
check('tolak tanpa X-Relay-Key', r.status === 401, `status ${r.status}`);

// 2. key salah
r = await worker.fetch(req('/v1beta/models/gemini-3.5-flash-lite:generateContent', 'salah'), env);
check('tolak key salah', r.status === 401, `status ${r.status}`);

// 3. path terlarang
r = await worker.fetch(req('/v1beta/models/gemini-3.5-flash-lite:generateContentX', 'rahasia-test'), env);
check('tolak path aneh', r.status === 404, `status ${r.status}`);

// 4. method GET
r = await worker.fetch(req('/v1beta/models/gemini-3.5-flash-lite:generateContent', 'rahasia-test', 'GET'), env);
check('tolak GET', r.status === 405, `status ${r.status}`);

// 5. sukses + query key diteruskan
calls.length = 0;
r = await worker.fetch(req('/v1beta/models/gemini-3.5-flash-lite:generateContent?key=KEY-RAHASIA', 'rahasia-test'), env);
const forwarded = calls[0]?.url ?? '';
check('teruskan ke Gemini', r.status === 200 && forwarded.startsWith('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'), forwarded);
check('query ?key diteruskan', forwarded.includes('key=KEY-RAHASIA'));

// 6. payload kebesaran
const big = 'x'.repeat(13 * 1024 * 1024);
r = await worker.fetch(req('/v1beta/models/gemini-3.5-flash-lite:generateContent', 'rahasia-test', 'POST', big), env);
check('tolak payload > 12MB', r.status === 413, `status ${r.status}`);

// 7. upstream error diteruskan apa adanya
globalThis.fetch = async () => new Response(JSON.stringify({ error: { message: 'User location is not supported for the API use.' } }), { status: 400 });
r = await worker.fetch(req('/v1beta/models/gemini-3.5-flash-lite:generateContent', 'rahasia-test'), env);
const body = await r.text();
check('status upstream 400 diteruskan', r.status === 400, `status ${r.status}`);
check('body error diteruskan', body.includes('User location is not supported'));

const failed = results.filter((x) => !x.ok).length;
console.log(`\n${results.length - failed}/${results.length} lulus`);
process.exit(failed === 0 ? 0 : 1);
