<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterMitraRequest;
use App\Models\MitraProfile;
use App\Models\User;
use App\Services\XenditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MitraRegisterController extends Controller
{
    /**
     * POST /api/auth/register-mitra — pendaftaran khusus Mitra UMKM.
     *
     * multipart/form-data: data owner + toko + dokumen (KTP/NIB) +
     * 1-3 foto toko + rekening pencairan. Berbeda dari auth/register
     * (JSON-only, field mitra opsional) yang tetap dipertahankan
     * untuk warga & kompatibilitas.
     *
     * Hasil selalu status_verifikasi=pending, is_active=false.
     * Cek rekening Lapis 1 (format + allowlist bank lokal, tanpa API key):
     * lolos -> bank_validation_status=format_valid, gagal ->
     * manual_review (pengajuan tetap masuk antrean admin, bukan ditolak).
     * Badge hijau "Valid/Ready" baru setelah bukti payout (Lapis 2).
     */
    public function store(RegisterMitraRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Lapis 1: format + allowlist bank lokal.
        $xendit = XenditService::fromConfig();
        $bankStatus = 'format_valid';
        $bankNote = null;

        try {
            $xendit->mapBankRouting($validated['nama_bank']);
            $xendit->validateAccountNumber($validated['nama_bank'], $validated['nomor_rekening']);
        } catch (RuntimeException $e) {
            $bankStatus = 'manual_review';
            $bankNote = $e->getMessage();
        }

        $storedPaths = [];

        try {
            $user = DB::transaction(function () use ($validated, $bankStatus, $bankNote) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'password' => Hash::make($validated['password']),
                    'role' => 'mitra',
                    'kota' => $validated['city'],
                    'kecamatan' => $validated['district'],
                    'kelurahan' => $validated['sub_district'],
                    'rt' => $validated['rt'],
                    'rw' => $validated['rw'],
                ]);

                MitraProfile::create([
                    'user_id' => $user->id,
                    'nama_usaha' => $validated['nama_usaha'],
                    'jenis_usaha' => $validated['jenis_usaha'],
                    'alamat_usaha' => $validated['alamat_usaha'],
                    'usaha_kelurahan' => $validated['usaha_kelurahan'],
                    'usaha_kecamatan' => $validated['usaha_kecamatan'],
                    'usaha_kota' => $validated['usaha_kota'],
                    'usaha_provinsi' => $validated['usaha_provinsi'],
                    'usaha_kode_pos' => $validated['usaha_kode_pos'] ?? null,
                    'nomor_ktp' => $validated['nomor_ktp'],
                    'nomor_nib' => $validated['nomor_nib'],
                    'nama_bank' => $validated['nama_bank'],
                    'nomor_rekening' => preg_replace('/[\s\-_.]/', '', $validated['nomor_rekening']),
                    'nama_pemilik_rekening' => $validated['nama_pemilik_rekening'],
                    'bank_validation_status' => $bankStatus,
                    'bank_validation_note' => $bankNote,
                    'status_verifikasi' => 'pending',
                    'is_active' => false,
                    'balance' => 0,
                ]);

                return $user;
            });

            // Simpan file setelah user ada (butuh user id untuk path).
            $fileFields = ['foto_ktp', 'foto_nib', 'foto_toko', 'foto_toko_2', 'foto_toko_3'];

            foreach ($fileFields as $field) {
                if ($request->hasFile($field)) {
                    $storedPaths[$field] = $request->file($field)->store("mitra/{$user->id}", 'public');
                }
            }

            $user->mitraProfile->update($storedPaths);
        } catch (Throwable $e) {
            // Bersihkan file yatim + user setengah jadi.
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            if (isset($user)) {
                $user->delete();
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Mitra registration failed. Please retry.',
            ], 500);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $mitra = $user->mitraProfile->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Mitra registered successfully. Waiting for admin verification.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
                'mitra' => [
                    'id' => $mitra->id,
                    'nama_usaha' => $mitra->nama_usaha,
                    'jenis_usaha' => $mitra->jenis_usaha,
                    'status_verifikasi' => $mitra->status_verifikasi,
                    'is_active' => (bool) $mitra->is_active,
                    'bank_validation_status' => $mitra->bank_validation_status,
                    'bank_validation_note' => $mitra->bank_validation_note,
                    'foto_urls' => [
                        'foto_toko' => $mitra->foto_toko ? Storage::url($mitra->foto_toko) : null,
                        'foto_toko_2' => $mitra->foto_toko_2 ? Storage::url($mitra->foto_toko_2) : null,
                        'foto_toko_3' => $mitra->foto_toko_3 ? Storage::url($mitra->foto_toko_3) : null,
                        'foto_ktp' => $mitra->foto_ktp ? Storage::url($mitra->foto_ktp) : null,
                        'foto_nib' => $mitra->foto_nib ? Storage::url($mitra->foto_nib) : null,
                    ],
                ],
            ],
        ], 201);
    }
}
