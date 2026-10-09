<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyMitraRequest;
use App\Http\Resources\MitraProductResource;
use App\Models\MitraProduct;
use App\Models\MitraProfile;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminController extends Controller
{
    /**
     * GET /api/admin/merchants?status=pending|verified|rejected — daftar
     * mitra per tab layar "Validasi Mitra UMKM". Summary counts untuk
     * badge tab (Menunggu/Diverifikasi/Ditolak).
     */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'pending');

        if (! in_array($status, ['pending', 'verified', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => ['status' => ['Status must be pending, verified, or rejected.']],
            ], 422);
        }

        $merchants = MitraProfile::with('user:id,name,email,phone')
            ->where('status_verifikasi', $status)
            ->orderBy('id')
            ->paginate(15);

        $summary = [
            'pending' => MitraProfile::where('status_verifikasi', 'pending')->count(),
            'verified' => MitraProfile::where('status_verifikasi', 'verified')->count(),
            'rejected' => MitraProfile::where('status_verifikasi', 'rejected')->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Merchants retrieved successfully.',
            'data' => $merchants,
            'summary' => $summary,
        ]);
    }

    /**
     * GET /api/admin/merchants/pending — alias backward-compat.
     * Dokumen (foto_ktp/nib/toko) nullable untuk data register lama —
     * tampilkan apa adanya, jangan 500.
     */
    public function pending(Request $request): JsonResponse
    {
        $request->mergeIfMissing(['status' => 'pending']);

        return $this->index($request);
    }

    /**
     * GET /api/admin/merchants/{id} — detail 1 pengajuan untuk kartu
     * "Validasi Mitra UMKM": toko, owner+kontak, lokasi usaha,
     * foto toko (3), dokumen KTP/NIB, blok rekening + status validasi bank.
     */
    public function show(int $id): JsonResponse
    {
        $mitra = MitraProfile::with('user:id,name,email,phone,kota,kecamatan,kelurahan,rt,rw')
            ->find($id);

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant not found.',
            ], 404);
        }

        $user = $mitra->user;

        return response()->json([
            'success' => true,
            'message' => 'Merchant detail retrieved successfully.',
            'data' => [
                'id' => $mitra->id,
                'store' => [
                    'nama_usaha' => $mitra->nama_usaha,
                    'jenis_usaha' => $mitra->jenis_usaha,
                    'registered_at' => $mitra->created_at?->format('Y-m-d'),
                ],
                'verification_status' => $mitra->status_verifikasi,
                'is_active' => (bool) $mitra->is_active,
                'verification_note' => $mitra->verification_note,
                'owner' => $user ? [
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'domisili' => trim("{$user->kota}, {$user->kecamatan}, {$user->kelurahan} RT {$user->rt}/RW {$user->rw}", ', '),
                ] : null,
                'lokasi_usaha' => [
                    'alamat' => $mitra->alamat_usaha,
                    'kelurahan' => $mitra->usaha_kelurahan,
                    'kecamatan' => $mitra->usaha_kecamatan,
                    'kota' => $mitra->usaha_kota,
                    'provinsi' => $mitra->usaha_provinsi,
                    'kode_pos' => $mitra->usaha_kode_pos,
                ],
                'foto_toko' => [
                    'foto_1' => $mitra->foto_toko ? Storage::url($mitra->foto_toko) : null,
                    'foto_2' => $mitra->foto_toko_2 ? Storage::url($mitra->foto_toko_2) : null,
                    'foto_3' => $mitra->foto_toko_3 ? Storage::url($mitra->foto_toko_3) : null,
                ],
                'dokumen' => [
                    'nomor_ktp' => $mitra->nomor_ktp,
                    'foto_ktp' => $mitra->foto_ktp ? Storage::url($mitra->foto_ktp) : null,
                    'nomor_nib' => $mitra->nomor_nib,
                    'foto_nib' => $mitra->foto_nib ? Storage::url($mitra->foto_nib) : null,
                ],
                'rekening' => [
                    'bank' => $mitra->nama_bank,
                    'nomor_rekening' => $mitra->nomor_rekening,
                    'atas_nama' => $mitra->nama_pemilik_rekening,
                    'bank_validation_status' => $mitra->bank_validation_status,
                    'bank_validated_at' => $mitra->bank_validated_at?->format('Y-m-d\TH:i:s'),
                    'bank_validation_note' => $mitra->bank_validation_note,
                ],
                'balance' => number_format((float) $mitra->balance, 2, '.', ''),
            ],
        ]);
    }

    /**
     * POST /api/admin/merchants/{id}/verify — approve / reject pengajuan.
     * Hanya dari status pending; sudah direview → 409 agar tidak
     * menimpa keputusan sebelumnya diam-diam.
     */
    public function verify(VerifyMitraRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $mitra = DB::transaction(function () use ($id, $validated) {
                $mitra = MitraProfile::where('id', $id)->lockForUpdate()->first();

                if (! $mitra) {
                    abort(404, 'Merchant not found.');
                }

                if ($mitra->status_verifikasi !== 'pending') {
                    abort(409, 'Merchant has already been reviewed.');
                }

                if ($validated['action'] === 'approve') {
                    $mitra->update([
                        'status_verifikasi' => 'verified',
                        'is_active' => true,
                        'verification_note' => $validated['reason'] ?? null,
                    ]);
                } else {
                    $mitra->update([
                        'status_verifikasi' => 'rejected',
                        'is_active' => false,
                        'verification_note' => $validated['reason'],
                    ]);
                }

                return $mitra->refresh();
            });
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'success' => true,
            'message' => $validated['action'] === 'approve'
                ? 'Merchant verified and activated.'
                : 'Merchant application rejected.',
            'data' => [
                'id' => $mitra->id,
                'store_name' => $mitra->nama_usaha,
                'verification_status' => $mitra->status_verifikasi,
                'is_active' => (bool) $mitra->is_active,
            ],
        ]);
    }

    /**
     * GET /api/admin/mitra-products — picker produk untuk funding voucher.
     * Filter: ?mitra_profile_id=&is_active=1&category=kuliner.
     * Hanya produk milik mitra verified+aktif yang layak didanai;
     * flag `fundable` menandainya agar UI bisa disable yang lain.
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'mitra_profile_id' => 'sometimes|integer|exists:mitra_profiles,id',
            'is_active' => 'sometimes|boolean',
            'category' => 'sometimes|string|in:'.implode(',', Voucher::CATEGORIES),
        ]);

        $query = MitraProduct::with('mitraProfile:id,nama_usaha,status_verifikasi,is_active')
            ->orderByDesc('id');

        if ($request->filled('mitra_profile_id')) {
            $query->where('mitra_profile_id', $request->integer('mitra_profile_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        $products = $query->paginate(15);

        $items = collect($products->items())->map(function ($p) {
            $res = (new MitraProductResource($p))->resolve();

            $mitra = $p->mitraProfile;
            $res['store_name'] = $mitra->nama_usaha ?? null;
            $res['fundable'] = (bool) ($p->is_active
                && $mitra
                && $mitra->status_verifikasi === 'verified'
                && $mitra->is_active);

            return $res;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Mitra products retrieved successfully.',
            'data' => $items,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }
}
