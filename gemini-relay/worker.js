/**
 * KarbonKita — Gemini Relay (Cloudflare Worker)
 *
 * Kenapa ini ada:
 *   Google menolak IP datacenter/VPS dengan HTTP 400
 *   "User location is not supported for the API use." (FAILED_PRECONDITION).
 *   Worker ini jalan di edge Cloudflare (IP yang diizinkan Google) dan
 *   meneruskan request ke Gemini apa adanya. Backend Laravel cukup mengarahkan
 *   GEMINI_BASE_URL ke URL Worker ini — model, prompt, dan gambar tetap sama,
 *   jadi AI-nya tetap Gemini asli (bukan mock).
 *
 * Keamanan:
 *   - Wajib header `X-Relay-Key` yang cocok dengan secret RELAY_KEY.
 *   - API key Gemini TIDAK disimpan di Worker dan TIDAK dikirim ke sini;
 *     backend menambahkan `?key=...` di URL seperti biasa.
 *   - Hanya path /v1beta/models/*:generateContent yang dilayani.
 *
 * Deploy:
 *   npm install
 *   npx wrangler deploy                   # catat URL https://...workers.dev
 *   npx wrangler secret put RELAY_KEY     # isi string acak panjang
 * (atau tanpa Node/npm: tempel file ini lewat dashboard Cloudflare — lihat README)
 */

const ALLOWED_PATH = /^\/v1beta\/models\/[A-Za-z0-9._-]+:generateContent$/;
const GEMINI_HOST = 'https://generativelanguage.googleapis.com';
const MAX_BODY_BYTES = 12 * 1024 * 1024; // samakan dengan batas upload backend

function json(body, status) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

const LOCATION_BLOCK = /user location is not supported/i;

/**
 * Teruskan ke Gemini dengan percobaan ulang.
 *
 * Google menolak sebagian IP egress Cloudflare dengan HTTP 400
 * "User location is not supported" (kebijakan IP datacenter). IP egress
 * dipilih per-request, jadi percobaan ulang kerap mendarat di IP yang
 * diterima. Hasil percobaan terakhir tetap dikembalikan apa adanya supaya
 * error aslinya masih terlihat bila semuanya gagal.
 */
async function forwardWithRetry(target, body, attempts = 3) {
  let last = null;

  for (let i = 1; i <= attempts; i++) {
    let upstream;

    try {
      upstream = await fetch(target, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'User-Agent': 'KarbonKita-Relay/1.0',
        },
        body,
      });
    } catch (err) {
      last = {
        status: 502,
        text: JSON.stringify({
          error: { message: `Relay upstream error: ${err && err.message ? err.message : 'unknown'}` },
        }),
        contentType: 'application/json',
        attempts: i,
      };

      continue;
    }

    const text = await upstream.text();

    last = {
      status: upstream.status,
      text,
      contentType: upstream.headers.get('Content-Type') || 'application/json',
      attempts: i,
    };

    if (upstream.status !== 400 || !LOCATION_BLOCK.test(text)) {
      return last;
    }
  }

  return last;
}

export default {
  async fetch(request, env) {
    if (request.method !== 'POST') {
      return json({ error: { message: 'Method not allowed' } }, 405);
    }

    // Pengaman: tanpa secret yang benar, relay tidak dipakai orang lain.
    const expected = env.RELAY_KEY || '';
    const provided = request.headers.get('X-Relay-Key') || '';
    if (!expected || provided !== expected) {
      return json({ error: { message: 'Unauthorized relay request' } }, 401);
    }

    const url = new URL(request.url);
    if (!ALLOWED_PATH.test(url.pathname)) {
      return json({ error: { message: 'Path not allowed' } }, 404);
    }

    const body = await request.text();
    if (body.length > MAX_BODY_BYTES) {
      return json({ error: { message: 'Payload too large' } }, 413);
    }

    // Teruskan query string (?key=...) apa adanya — key tidak di-log.
    const target = `${GEMINI_HOST}${url.pathname}${url.search}`;

    const result = await forwardWithRetry(target, body);

    return new Response(result.text, {
      status: result.status,
      headers: {
        'Content-Type': result.contentType,
        'X-Relay-Upstream-Status': String(result.status),
        'X-Relay-Attempts': String(result.attempts),
      },
    });
  },
};
