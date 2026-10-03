# Laporan Task 5 — Finalisasi Postman + docs

Tanggal: 2026-09-05
Scope roadmap: `roadmap-fase4.md` Task 5

## 1. Tujuan
Koleksi Postman bisa dijalankan berurutan dari nol sampai seluruh alur Fase 4
tanpa menebak urutan/variable.

## 2. Perubahan (`KarbonKita_API.postman_collection.json` saja)
- Validasi struktur: **69 requests, 9 folder, 0 invalid** (semua punya
  method + URL), 11 collection variables.
- Folder `Merchant` (10): Setup login kopilokal → dashboard success/401/403 →
  toggle → redeem success/double-409/unknown-422/warga-403.
- Folder `Admin` (6): Setup register mitra → pending list/403 → approve →
  re-verify 409 → reject.
- Rantai variable otomatis: claim → `qr_token`/`claim_id`; login mitra →
  `mitra_token`; login admin → `admin_token`; register mitra → kandidat
  `pending_mitra_id` (dengan catatan: samakan ke id mitra_profile dari Pending List).
- Deskripsi koleksi: tambah panduan `## End-to-end Fase 4` (5 langkah run order).

## 3. Cara testing (bisa langsung dicoba)
1. `cd backend && php artisan db:seed` (warga rafi, mitra kopilokal, admin).
2. `php artisan serve` (butuh MySQL `karbonkita` hidup + `php artisan migrate --force`).
3. Import koleksi ke Postman → ikuti `## End-to-end Fase 4` dari Authentication.
4. Satu-satunya langkah manual: salin id mitra_profile dari Pending List ke
   `pending_mitra_id` (didokumentasikan di request Setup + deskripsi).

## 4. Hasil
- `route:list` konfirmasi 5 route Fase 4 terdaftar: `merchant.dashboard`,
  `merchant.status`, `vouchers.redeem` (throttle 30,1), `admin.merchants.pending`,
  `admin.merchants.verify`.
- JSON valid, tidak ada request rusak.

## 5. Langkah berikutnya (Task 6)
Gate kualitas akhir: `composer test` + `pint` + tandai `roadmap-fase4.md` SELESAI.
