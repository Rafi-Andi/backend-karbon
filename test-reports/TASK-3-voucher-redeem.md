# Laporan Task 3 — Voucher redeem + Xendit payout (inti Fase 4)

Tanggal: 2026-09-05
Scope roadmap: `roadmap-fase4.md` Task 3 + §4.2, keputusan D1/D2/D4-D8

## 1. Tujuan
Kasir mitra scan QR warga → payout rupiah via Xendit → saldo toko bertambah.
Gagal payout → rollback total (guardrail `PROJECT_CONTEXT.md:68`).

## 2. File baru / diubah

| File | Aksi |
|------|------|
| `backend/app/Http/Requests/RedeemVoucherRequest.php` | BARU — `unique_code required\|string\|exists:voucher_claims,qr_token` + envelope 422. |
| `backend/app/Http/Controllers/MerchantController.php` | EDIT — tambah `redeem()` (transaksi + Xendit + audit). |
| `backend/routes/api.php` | EDIT — `POST vouchers/redeem` dalam grup `role:mitra` + `throttle:30,1` (D6). URL tetap kontrak. |
| `backend/tests/Feature/VoucherRedeemTest.php` | BARU — 9 test (lihat §5). |
| `KarbonKita_API.postman_collection.json` | EDIT — 4 request redeem di folder `Merchant` (success, double-409, unknown-422, warga-403). |

## 3. Alur `redeem()` (semua dalam `DB::transaction + lockForUpdate`)
1. Kunci `mitra_profiles` milik kasir → null 403; `pending/rejected/nonaktif` 403 (D5).
2. Kunci `voucher_claims` by `qr_token` (input bernama `unique_code`, D2) → null 404.
3. `used` → 409; `expired`/voucher expired/nonaktif → 422.
4. `voucher.mitra_profile_id != mitra.id` → 403 (D4, anti lintas-toko).
5. `XenditService::createDisbursement` (amount dari `rupiah_value`, JANGAN dari client; `external_id` D8; `bank_code` via `mapBankCode` D7) → throw = rollback.
6. Sukses: claim `used + used_at`, `disbursements` completed/pending + `response_log`, `balance += rupiah_value` hanya jika completed.
7. Return 200 `{claim_id, qr_token, voucher_title, amount, new_balance, xendit_disbursement_id, disbursement_status}`; Xendit throw → 502 + claim tetap `claimed` (nol baris setengah jadi).

## 4. Cara testing (bisa langsung dicoba)
```bash
cd backend
php artisan test --filter=VoucherRedeemTest
composer test
```
Postman (MySQL + `php artisan serve` + `XENDIT_MOCK=true`):
1. `Authentication > Login` sebagai warga (isi `auth_token`) → `Marketplace > Claim Voucher - Success` (isi `qr_token` otomatis).
2. `Merchant > Setup - Login Mitra` (isi `mitra_token`; pastikan toko `is_open=true` via dashboard).
3. `Merchant > Redeem - Success` → 200; ulangi request sama → `Double Redeem 409`.
4. Xendit real: set `XENDIT_MOCK=false` + key lalu `Http::fake` tidak berlaku di manual — gagal payout asli akan 502 + claim tetap `claimed` (cek `GET user/my-vouchers` status masih active).

## 5. Hasil
- `VoucherRedeemTest`: **9 passed, 25 assertions** (401, 403 warga, validasi 422, success + cek claim used + disbursement + balance, double 409, lintas-toko 403, expired 422, pending-mitra 403, Xendit-500 → 502 + rollback terbukti).
- Full suite: **89 passed, 383 assertions** (80 → 89, nol regresi).
- Pint file Task 3: **PASS**.

## 6. Langkah berikutnya (Task 4)
Admin `pending + verify` (`AdminController`, `VerifyMitraRequest`, route
`role:admin`, `AdminMitraTest`, folder Postman `Admin`).
