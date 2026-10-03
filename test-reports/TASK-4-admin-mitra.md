# Laporan Task 4 — Admin verifikasi mitra (pending + approve/reject)

Tanggal: 2026-09-05
Scope roadmap: `roadmap-fase4.md` Task 4 + §4.3/§4.4

## 1. Tujuan
Super Admin memverifikasi pengajuan UMKM: lihat antrean + dokumen,
setujui (aktif) atau tolak (dengan alasan tercatat).

## 2. File baru / diubah

| File | Aksi |
|------|------|
| `backend/app/Http/Controllers/AdminController.php` | BARU — `pending()` (paginate 15 + eager user) + `verify(id)` (lock + 404/409 envelope). |
| `backend/app/Http/Requests/VerifyMitraRequest.php` | BARU — `action approve\|reject`, `reason required_if reject, max:500` + envelope 422. |
| `backend/routes/api.php` | EDIT — grup `role:admin`: `GET admin/merchants/pending`, `POST admin/merchants/{id}/verify`. |
| `backend/tests/Feature/AdminMitraTest.php` | BARU — 9 test (lihat §5). |
| `KarbonKita_API.postman_collection.json` | EDIT — folder `Admin` (6 request) + variable `pending_mitra_id`. |

## 3. Desain
- `abort(404/409)` di dalam transaksi di-catch menjadi envelope
  `{success:false}` (konsisten dengan endpoint lain, bukan error HTML Laravel).
- Verify ulang → `409` agar keputusan lama tidak tertimpa diam-diam.
- `verification_note` (migrasi Task 1) menyimpan alasan approve/reject.
- Role memakai `role:admin`; alias `super_admin` tetap valid via `EnsureRole`.
- Dokumen KTP/NIB/toko nullable (data register lama tidak punya file) —
  pending tetap tampil, tidak 500. Upload dokumen = follow-up di luar Fase 4.

## 4. Cara testing (bisa langsung dicoba)
```bash
cd backend
php artisan test --filter=AdminMitraTest
composer test
```
Postman (MySQL + `php artisan serve` + seed admin):
1. `Authentication > Login - Success (Admin)` → isi `admin_token`.
2. Folder `Admin` berurutan: `Setup - Register Fresh Mitra` → `Get Pending`
   (cari warung baru di `data.data`, salin **id mitra_profile** ke
   `pending_mitra_id` — bukan user id) → `Verify - Approve` (200 verified) →
   `Re-verify` (409) → register fresh lagi → `Reject` (200 rejected).
3. Mitra yang di-approve langsung bisa dipakai di folder `Merchant`
   (dashboard `can_redeem: true`, redeem 200).

## 5. Hasil
- `AdminMitraTest`: **9 passed, 26 assertions** (401, 403 warga+mitra, pending
  hanya pending + eager user, approve aktif, reject + note, reject tanpa reason
  422, re-verify 409, 404, action invalid 422).
- Full suite: **98 passed, 409 assertions** (89 → 98, nol regresi).
- Pint file Task 4: **PASS** (auto-fix indentasi `AdminController`, import order
  `routes/api.php`).

## 6. Langkah berikutnya (Task 5 + 6)
Finalisasi Postman (cek urutan run end-to-end) + gate kualitas
(`composer test`, `pint`, update `roadmap-fase4.md` status SELESAI).
