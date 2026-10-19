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

### Opsi A — dashboard Cloudflare (tanpa Node/npm)

1. Buka `https://dash.cloudflare.com` → **Workers & Pages** → **Create application** → **Create Worker**.
2. Nama: `karbonkita-gemini-relay` → **Deploy**. Daftarkan subdomain `workers.dev` bila diminta.
3. **Edit code** → tempel seluruh isi `worker.js` (timpa contoh Hello World) → **Deploy**.
4. **Settings** → **Variables and Secrets** → **Add** → Type **Secret**, Name `RELAY_KEY`, Value string acak → **Deploy**.
5. Catat URL-nya: `https://karbonkita-gemini-relay.<subdomain>.workers.dev`.

### Opsi B — Wrangler CLI

Urutan penting: **deploy dulu, baru secret**. `wrangler secret put` gagal ("worker not found") bila Worker-nya belum pernah ada.

```bash
cd gemini-relay
npm install
npx wrangler login
npx wrangler deploy                   # catat URL https://karbonkita-gemini-relay.<subdomain>.workers.dev
npx wrangler secret put RELAY_KEY     # isi string acak panjang, simpan nilainya
```

### Troubleshooting npm 11 (`allowScripts`)

npm 11 menahan postinstall dependency, sehingga binary platform `workerd` tidak terpasang dan **semua** perintah wrangler gagal dengan:

```
Error: The package "@cloudflare/workerd-windows-64" could not be found, and is needed by workerd.
```

Perbaikan: setujui install script, lalu pasang binary platform secara eksplisit (versinya harus sama dengan yang diminta `node_modules/workerd/package.json`).

```bash
npm install-scripts approve esbuild sharp workerd

# versi yang dibutuhkan
VER=$(node -p "require('./node_modules/workerd/package.json').optionalDependencies['@cloudflare/workerd-windows-64']")

# Windows x64 (pakai nilai $VER di atas; di PowerShell: npm install "@cloudflare/workerd-windows-64@$VER" --no-save)
npm install "@cloudflare/workerd-windows-64@$VER" --no-save

npx wrangler --version                # harus mencetak nomor versi, bukan error
```

Catatan: `--no-save` sengaja dipakai supaya paket khusus-Windows ini tidak masuk `package.json` (dependensi platform aslinya diurus `optionalDependencies` milik `workerd`). Perintah di atas perlu diulang setiap `node_modules` dibuat ulang di mesin Windows dengan npm 11+.

### Login gagal dari terminal non-interaktif

`wrangler login` membuka browser dan menunggu callback ke `localhost:8976`. Di terminal non-interaktif / sesi SSH, callback itu bisa tidak pernah sampai dan login gagal dengan pesan error kosong. Pakai device flow (butuh Wrangler >= 4.119):

```bash
npx wrangler login --device    # cetak URL + kode, masukkan di https://dash.cloudflare.com/oauth2/device
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
