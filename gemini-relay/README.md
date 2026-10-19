# KarbonKita — Gemini Relay

Relay tipis (Cloudflare Worker) supaya backend di VPS yang **IP-nya diblokir Google** tetap memakai **Gemini asli**, bukan mock.

## Masalah yang diselesaikan

Dari VPS produksi:

```json
{
  "error": {
    "code": 400,
    "message": "User location is not supported for the API use.",
    "status": "FAILED_PRECONDITION"
  }
}
```

Key dan model **benar** (dari mesin lokal request yang sama sukses HTTP 200). Yang ditolak adalah **IP datacenter** VPS. Indonesia ada di daftar region resmi Gemini, jadi ini pemblokiran IP hosting, bukan negara.

## Cara kerja

```
App Flutter → Laravel (VPS) → Cloudflare Worker (edge, IP diizinkan) → Gemini API
                                        ↑
                              GEMINI_BASE_URL=https://<worker>.workers.dev
```

- Prompt, model, dan gambar **tidak diubah** — tetap Gemini Vision asli.
- API key Gemini **tidak** disimpan di Worker; backend tetap mengirim `?key=...`.
- Worker hanya melayani `POST /v1beta/models/<model>:generateContent`.

## Deploy

```bash
cd gemini-relay
npm install
npx wrangler login
npx wrangler secret put RELAY_KEY     # isi string acak panjang, simpan nilainya
npx wrangler deploy                   # catat URL https://karbonkita-gemini-relay.<subdomain>.workers.dev
```

Uji dari VPS (harus HTTP 200, bukan 400 lokasi):

```bash
RELAY="https://karbonkita-gemini-relay.<subdomain>.workers.dev"
KEY=$(grep -E '^GEMINI_API_KEY=' /opt/karbonkita/.env | cut -d= -f2- | tr -d '"'"'" | tr -d '[:space:]')
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
  "$RELAY/v1beta/models/gemini-3.5-flash-lite:generateContent?key=$KEY" \
  -H "X-Relay-Key: <RELAY_KEY>" \
  -H 'Content-Type: application/json' \
  -d '{"contents":[{"parts":[{"text":"hi"}]}]}'
```

## Pasang di backend (VPS)

```bash
cd /opt/karbonkita
sed -i 's|^GEMINI_BASE_URL=.*||' .env
printf 'GEMINI_BASE_URL=%s\nGEMINI_RELAY_KEY=%s\n' \
  "https://karbonkita-gemini-relay.<subdomain>.workers.dev" "<RELAY_KEY>" >> .env
docker compose restart app queue
docker compose logs -f app      # cari "Gemini API request failed" bila masih gagal
```

Pastikan `GEMINI_MOCK=false` dan `GEMINI_MODEL=gemini-3.5-flash-lite`.

## Alternatif tanpa Cloudflare

Set `GEMINI_PROXY` ke proxy residensial (bukan datacenter):

```env
GEMINI_PROXY=http://user:pass@proxy-residensial:port
```

Backend mendukung keduanya tanpa perubahan kode.
