<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateVoucherRequest;
use App\Http\Resources\AdminVoucherClaimResource;
use App\Http\Resources\AdminVoucherResource;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Kelola Reward (voucher marketplace) untuk panel admin.
 *
 * Halaman admin butuh: daftar SEMUA reward (termasuk nonaktif &
 * kedaluwarsa), siapa saja yang sudah menukarkannya, nonaktif/aktifkan,
 * serta ubah stok & tenggat. Nonaktif hanya menghentikan klaim baru —
 * voucher yang sudah ditukar tetap bisa diredeem sampai tenggatnya
 * (lihat MerchantController::redeem).
 */
class AdminVoucherController extends Controller
{
    /**
     * GET /api/admin/vouchers — semua reward, terbaru dulu.
     */
    public function index(): JsonResponse
    {
        $vouchers = $this->queryWithCounts()->latest('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Vouchers retrieved successfully.',
            'data' => AdminVoucherResource::collection($vouchers),
        ]);
    }

    /**
     * GET /api/admin/vouchers/{id} — detail satu reward.
     */
    public function show(int $id): JsonResponse
    {
        $voucher = $this->findWithCounts($id);

        if (! $voucher) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'message' => 'Voucher retrieved successfully.',
            'data' => new AdminVoucherResource($voucher),
        ]);
    }

    /**
     * GET /api/admin/vouchers/{id}/claims — siapa yang menukarkan
     * reward ini (terbaru dulu, 20 per halaman).
     */
    public function claims(int $id): JsonResponse
    {
        $voucher = Voucher::find($id);

        if (! $voucher) {
            return $this->notFound();
        }

        $claims = $voucher->claims()
            ->with('user:id,name,phone,rt,rw')
            ->orderByDesc('claimed_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Voucher claims retrieved successfully.',
            'data' => AdminVoucherClaimResource::collection($claims->items()),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
                'last_page' => $claims->lastPage(),
            ],
        ]);
    }

    /**
     * PATCH /api/admin/vouchers/{id}/status — aktif/nonaktif saja.
     * Pengganti hapus: klaim & transaksi poin tetap utuh.
     */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_active' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $voucher = Voucher::find($id);

        if (! $voucher) {
            return $this->notFound();
        }

        $voucher->update(['is_active' => (bool) $validator->validated()['is_active']]);

        return response()->json([
            'success' => true,
            'message' => $voucher->is_active
                ? 'Voucher activated successfully.'
                : 'Voucher deactivated successfully.',
            'data' => new AdminVoucherResource($this->findWithCounts($id)),
        ]);
    }

    /**
     * PATCH /api/admin/vouchers/{id} — ubah stok & tenggat manual.
     */
    public function update(UpdateVoucherRequest $request, int $id): JsonResponse
    {
        $voucher = Voucher::find($id);

        if (! $voucher) {
            return $this->notFound();
        }

        $voucher->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Voucher updated successfully.',
            'data' => new AdminVoucherResource($this->findWithCounts($id)),
        ]);
    }

    private function queryWithCounts()
    {
        return Voucher::query()
            ->with('mitraProfile.user')
            ->withCount([
                'claims',
                'claims as used_claims_count' => fn ($query) => $query->where('status', 'used'),
            ]);
    }

    private function findWithCounts(int $id): ?Voucher
    {
        return $this->queryWithCounts()->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Voucher not found.',
        ], 404);
    }
}
