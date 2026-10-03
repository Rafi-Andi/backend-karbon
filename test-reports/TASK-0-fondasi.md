# Laporan Task 0 — Fondasi Fase 4 (role middleware + env + admin seeder)

Tanggal: 2026-09-05
Scope roadmap: `roadmap-fase4.md` Task 0

## 1. Tujuan
Memasang pagar peran + konfigurasi Xendit + akun admin sebelum logika bisnis
Fase 4 ditulis, agar endpoint merchant/admin nantinya aman sejak awal.

## 2. File baru / diubah

| File | Aksi |
|------|------|
| `backend/app/Http/Middleware/EnsureRole.php` | BARU — cek `role`, 401 jika tamu, 403 envelope jika peran salah. Mendukung `role:mitra`, `role:admin`, dan multi `role:mitra,admin`. |
| `backend/bootstrap/app.php` | EDIT — alias `role => EnsureRole::class` (alias lama `auth.society` dipertahankan). |
| `backend/config/services.php` | EDIT — tambah array `xendit` (key, base_url, mock, timeout, callback_token). |
| `backend/.env.example` | EDIT — tambah `XENDIT_API_KEY/BASE_URL/MOCK/TIMEOUT/CALLBACK_TOKEN`. |
| `backend/.env` (lokal, tidak di-commit) | EDIT — tambah blok XENDIT yang sama (`MOCK=true`). |
| `backend/database/seeders/AdminSeeder.php` | BARU — `firstOrCreate admin@karbonkita.id / SecurePassword123! / role admin`. Rerun aman. |
| `backend/database/seeders/DatabaseSeeder.php` | EDIT — daftarkan `AdminSeeder`. |
| `backend/tests/Feature/EnsureRoleTest.php` | BARU — 3 test: 401 tamu, 403 peran salah + envelope, 200 peran benar. |
| `KarbonKita_API.postman_collection.json` | EDIT — tambah variable `admin_token`, `mitra_token`; tambah request `Login - Success (Admin, seeded)` yang auto-save `admin_token`; tambah catatan Task 0 di deskripsi koleksi. |

## 3. Keputusan penting (deviasi dari plan awal)
- **Role admin = `admin`, bukan `super_admin`.** Migrasi `users.role` enum hanya
  mengizinkan `['warga','mitra','admin']` (terbukti via test gagal CHECK constraint
  saat pakai `super_admin`). Agar tanpa migrasi berisiko, standar kode = `admin`
  (sesuai DB + `UserFactory::admin()`), dan `EnsureRole` menganggap `admin` ≡
  `super_admin` (alias dua arah) sehingga route boleh tulis salah satu.
  `roadmap-fase4.md` yang menulis `role:super_admin` tetap valid berkat alias ini.
- **Tidak ada endpoint baru di Task 0** — Postman hanya fondasi (variable + login
  admin) agar Task 2-4 bisa langsung testing per task.

## 4. Cara testing (bisa langsung dicoba)
```bash
cd backend
php artisan test --filter=EnsureRoleTest
composer test   # full suite
vendor/bin/pint --test "app/Http/Middleware/EnsureRole.php" "database/seeders/AdminSeeder.php" "tests/Feature/EnsureRoleTest.php" "bootstrap/app.php" "config/services.php"
```
Postman (butuh MySQL dev + `php artisan serve`):
1. `php artisan db:seed --class=AdminSeeder` (atau `php artisan db:seed` penuh).
2. Jalankan `Authentication > Login - Success (Admin, seeded)` → `admin_token` terisi.
3. Endpoint merchant/admin Fase 4 (Task 2-4) akan memakai `admin_token`/`auth_token` ini.

## 5. Hasil
- `EnsureRoleTest`: **3 passed** (401, 403 envelope, 200).
- Full suite: **67 passed, 324 assertions** (naik dari 64 — tambah 3 test baru, nol regresi).
- Pint pada 5 file Task 0: **PASS** (1 auto-fix quote di `bootstrap/app.php`).
- Pint full-repo `--test` masih merah pada file lama (migrasi 2024_*, seeder lama,
  `routes/api.php` EOF) — pre-existing, tidak disentuh agar diff minimal.

## 6. Langkah berikutnya (Task 1)
`XenditService` + migrasi `verification_note` + `XenditServiceTest`, mengikuti pola
`GeminiService` (mock via env, timeout 20s, throw agar redeem bisa rollback).
