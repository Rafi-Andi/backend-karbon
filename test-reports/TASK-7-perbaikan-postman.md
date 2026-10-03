# Laporan Perbaikan Postman + Temuan Testing Xendit Real

Tanggal: 2026-09-05
Pemicu: "environments tidak terbaca + error di bagian admin"

## 1. Akar masalah (3 bug, semua diperbaiki)

| # | Gejala | Penyebab | Perbaikan |
|---|--------|----------|-----------|
| 1 | Variable/token "tidak terbaca", request 401 padahal sudah login | Test script menulis ke `pm.collectionVariables`, tapi jika user memakai Environment sendiri (atau variable kosong di-level environment), Postman mengutamakan Environment → token tertutup nilai kosong | Buat `KarbonKita.postman_environment.json` resmi + migrasi 11 baris script ke `pm.environment.set/get` (0 sisa `collectionVariables`) |
| 2 | Verify admin selalu 404 | Setup menyimpan `user.id` ke `pending_mitra_id`, padahal endpoint butuh `mitra_profile.id` | Setup tidak lagi menyimpan ID salah; `Get Pending` auto-save `pending_mitra_id` = mitra_profile terbaru bernama "Verify" (fallback: entri terakhir). Langkah manual dihapus. |
| 3 | Setup admin kadang 422 intermiten | Phone `+62877999{{$randomInt}}` bisa jadi 9 digit saat random < 100 (regex butuh 10-15 digit) | Basis phone dipadatkan (`+628779900{{$randomInt}}` → selalu 10-13 digit, tervalidasi untuk random 0-1000). Pola rapuh yang sama diperbaiki di Register Warga/Mitra + Setup Quiz. Email setup dibuat ganda-random agar rerun aman. |

Tambahan: path verify diubah ke standar Postman (`:id` + variable mapping),
sehingga tidak ada warning unresolved; deskripsi koleksi diawali panduan
"## Cara pakai" (import 2 file, pilih environment, jangan isi manual).

## 2. Cara pakai yang benar (mulai sekarang)
1. Import `KarbonKita_API.postman_collection.json` DAN `KarbonKita.postman_environment.json`.
2. Pilih environment **KarbonKita Local** di dropdown kanan atas (jangan No Environment).
3. Jalankan sesuai `## End-to-end Fase 4`. Semua token terisi otomatis.

## 3. Validasi
- Kedua JSON valid; 69 requests / 0 invalid; semua `{{var}}` ter-resolve ke
  variable yang dikenal; semua phone register valid untuk random 0-1000
  (kecuali request negatif yang memang sengaja invalid).
- Backend tidak disentuh kecuali 1 hardening test (lihat §4).

## 4. Temuan testing Xendit real (bonus diagnosis)
`.env` lokal sudah berisi key Development asli + `XENDIT_MOCK=false` (langkah
benar!), tapi ini membuat 2 test redeem ikut menembak API asli → 502.enged:
- Test di-hardening: `VoucherRedeemTest::setUp` memaksa mock, sehingga suite
  deterministik apa pun isi `.env` → kembali **98 passed, 409 assertions**.
- Diagnosis read-only (GET, tanpa transaksi): key TER-AUTENTIKASI tapi
  **403 insufficient permissions** → key perlu permission Disbursements/Payouts
  di Dashboard (Settings → API Keys → permissions). Sampai itu dinyalakan,
  testimoni 404/502 dari API asli adalah masalah permission, bukan bug kode.
- Alur resmi test mode (dok. Xendit): create → `PENDING` → callback
  `COMPLETED/FAILED` ke webhook. Kode kita sudah menangani ini (status disimpan
  apa adanya, saldo += hanya saat completed). Webhook tetap follow-up.

Langkah Anda berikutnya untuk payout beneran:
1. Nyalakan permission Disbursement/Payouts (write) pada key Development.
2. `Redeem - Success` via Postman dengan `XENDIT_MOCK=false` → ekspektasi 200
   dengan `disbursement_status: pending` + ID Xendit asli → cek di dashboard Xendit.
3. Webhook `COMPLETED` (task berikutnya) agar saldo bertambah otomatis.
