# Laporan Task 2 — Merchant dashboard (+ toggle Buka/Tutup)

Tanggal: 2026-09-05
Scope roadmap: `roadmap-fase4.md` Task 2 + §4.1, keputusan D3/D9

## 1. Tujuan
Endpoint pertama Fase 4 yang bisa dicoba via Postman: dashboard toko
(saldo, status, statistik, riwayat pencairan) + ekstra toggle Buka/Tutup.

## 2. File baru / diubah

| File | Aksi |
|------|------|
| `backend/app/Http/Controllers/MerchantController.php` | BARU — `dashboard()` + `updateStatus()`. |
| `backend/app/Http/Requests/MerchantStatusRequest.php` | BARU — `is_open required\|boolean` + envelope 422. |
| `backend/routes/api.php` | EDIT — grup `role:mitra`: `GET merchant/dashboard`, `PATCH merchant/status`. |
| `backend/tests/Feature/MerchantDashboardTest.php` | BARU — 6 test (401, 403 warga, dashboard verified, pending `can_redeem=false`, toggle, validasi). |
| `KarbonKita_API.postman_collection.json` | EDIT — folder `Merchant` (6 request) + catatan Task 2 di deskripsi. |

## 3. Desain (mapping kontrak ↔ DB)
- `is_active` ↔ `is_open`, `balance` ↔ `saldo`, `nama_usaha` ↔ `store_name` (D3).
- Mitra tanpa profile → `403`; mitra `pending` tetap `200` dengan
  `can_redeem: false` (kasir tahu penyebabnya, bukan 404 misterius).
- `balance`/`total_disbursed` diformat string 2 desimal agar konsisten dengan
  cast `decimal:2` (contoh `150000.00`).
- Toggle `PATCH merchant/status` adalah ekstra D9 (kontrak hanya menampilkan
  status). Hapus 1 route + 1 method jika tidak diinginkan — redeem/dashboard
  tidak bergantung padanya.

## 4. Cara testing (bisa langsung dicoba)
```bash
cd backend
php artisan test --filter=MerchantDashboardTest
composer test
```
Postman (butuh MySQL dev + `php artisan serve` + seed):
1. `php artisan db:seed` (mitra `kopilokal@example.com` verified+active).
2. Folder `Merchant` → jalankan berurutan:
   `Setup - Login Mitra` (isi `mitra_token`) → `Get Dashboard - Success` →
   `Fail 401 / 403` → `Patch Toggle Close` → `Get Dashboard` lagi
   (`is_open` berubah) → kembalikan `true` agar Task 3 bisa redeem
   (redeem butuh toko aktif).

## 5. Hasil
- `MerchantDashboardTest`: **6 passed, 21 assertions**.
- Full suite: **80 passed, 358 assertions** (74 → 80, nol regresi).
- Pint file Task 2: **PASS** (1 auto-fix EOF di `routes/api.php`).

## 6. Langkah berikutnya (Task 3)
`POST vouchers/redeem` — inti Fase 4 (transaksi + `XenditService` + rollback).
