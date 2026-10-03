# Laporan Task 1 — XenditService + migrasi verification_note

Tanggal: 2026-09-05
Scope roadmap: `roadmap-fase4.md` Task 1

## 1. Tujuan
Menyiapkan service payout Xendit (server-side, mockable) + kolom catatan
verifikasi mitra, mengikuti pola `GeminiService` (mock via env, timeout, throw
agar redeem bisa rollback).

## 2. File baru / diubah

| File | Aksi |
|------|------|
| `backend/app/Services/XenditService.php` | BARU — `fromConfig()`, `isMock()`, `buildExternalId()`, `mapBankCode()`, `createDisbursement()`. |
| `backend/tests/Unit/XenditServiceTest.php` | BARU — 7 test (mock, empty-key-mock, real sukses, real gagal throw, payload invalid, bank mapping, external_id unik). |
| `backend/database/migrations/2026_09_05_123024_add_verification_note_to_mitra_profiles_table.php` | BARU — `verification_note nullable text after status_verifikasi` (+ down). |
| `backend/app/Models/MitraProfile.php` | EDIT — tambah `verification_note` ke `$fillable` (+ auto-fix EOF newline oleh pint). |
| Postman | TIDAK ADA perubahan endpoint — Task 1 unit-level, tidak ada HTTP baru. Testing via `php artisan test` (lihat §4). |

## 3. Desain XenditService (ringkas)
- Konstruk baca `config('services.xendit')` (diisi Task 0). `isMock()` true jika
  `XENDIT_MOCK=true` ATAU key kosong — aman untuk dev/test tanpa key asli.
- Mock return `{xendit_id: xnd_mock_*, status: completed}` tanpa HTTP
  (dibuktikan `Http::preventStrayRequests()` di test).
- Real mode: `Http::withBasicAuth(key,'')->timeout(20)->post(base/v2/disbursements)`
  (Basic Auth sesuai API Xendit). `failed()` → throw `RuntimeException` sehingga
  `MerchantController@redeem` (Task 3) otomatis rollback total.
- `mapBankCode('Bank BCA') → 'BCA'`; tak dikenal → kembalikan input + Xendit yang
  menolak dengan pesan jelas (di-log di `response_log` saat Task 3).
- `buildExternalId(claimId) → KBK-{id}-{YmdHis}-{RAND4}` unik + traceable;
  kolom `xendit_disbursement_id` unique menjamin idempotency.

## 4. Cara testing
```bash
cd backend
php artisan test --filter=XenditServiceTest
composer test   # full suite (membuktikan migrasi baru jalan di SQLite)
vendor/bin/pint --test "app/Services/XenditService.php" "tests/Unit/XenditServiceTest.php" "app/Models/MitraProfile.php"
```
Catatan MySQL dev: `php artisan migrate --force` GAGAL di mesin ini karena MySQL
`127.0.0.1:3306` refused (service tidak jalan). Migrasi sudah terbukti jalan di
SQLite via full suite; jalankan `php artisan migrate --force` setelah MySQL hidup
sebelum testing Postman Task 3-4.

## 5. Hasil
- `XenditServiceTest`: **7 passed, 13 assertions**.
- Full suite: **74 passed, 337 assertions** (67 → 74, nol regresi).
- Pint pada file Task 1: **PASS** (1 auto-fix EOF newline di `MitraProfile.php`).

## 6. Langkah berikutnya (Task 2)
`MerchantController@dashboard` + route `role:mitra` + `MerchantDashboardTest` +
folder Postman `Merchant` (endpoint pertama yang bisa dicoba via Postman).
