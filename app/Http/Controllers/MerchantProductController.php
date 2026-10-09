<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMitraProductRequest;
use App\Http\Requests\UpdateMitraProductRequest;
use App\Http\Requests\UploadProductPhotoRequest;
use App\Http\Resources\MitraProductResource;
use App\Models\MitraProduct;
use App\Models\MitraProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MerchantProductController extends Controller
{
    /**
     * GET /api/merchant/products — katalog produk milik toko sendiri.
     * Mitra pending boleh draft; admin hanya memakai yang verified+aktif.
     */
    public function index(): JsonResponse
    {
        $mitra = $this->ownMitra();

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $products = MitraProduct::where('mitra_profile_id', $mitra->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => MitraProductResource::collection($products),
        ]);
    }

    /**
     * POST /api/merchant/products — mitra input produk + harga fix.
     * points_cost TIDAK diinput; dihitung admin saat funding via EcoRate.
     * Terima multipart opsional `foto_produk`; bila ada, file disimpan dan
     * otomatis jadi foto voucher saat produk didanai admin (snapshot).
     */
    public function store(StoreMitraProductRequest $request): JsonResponse
    {
        $mitra = $this->ownMitra();

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $validated = $request->validated();

        $product = MitraProduct::create([
            'mitra_profile_id' => $mitra->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'category' => $validated['category'] ?? 'kuliner',
            'image_url' => $validated['image_url'] ?? null,
            'rupiah_value' => $validated['rupiah_value'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if ($request->hasFile('foto_produk')) {
            try {
                $path = $request->file('foto_produk')->store("mitra-products/{$mitra->id}", 'public');
                $product->update(['image_url' => $path]);
            } catch (Throwable $e) {
                $product->delete();
                report($e);

                return response()->json([
                    'success' => false,
                    'message' => 'Product photo upload failed. Please retry.',
                ], 500);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => new MitraProductResource($product->refresh()),
        ], 201);
    }

    /**
     * POST /api/merchant/products/{id}/photo — ganti foto produk (multipart
     * `foto_produk`). Foto baru otomatis dipakai voucher batch berikutnya;
     * batch yang sudah terbit tidak berubah (snapshot).
     */
    public function uploadPhoto(UploadProductPhotoRequest $request, int $id): JsonResponse
    {
        $mitra = $this->ownMitra();

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $product = MitraProduct::where('id', $id)
            ->where('mitra_profile_id', $mitra->id)
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $old = $product->image_url;

        try {
            $path = $request->file('foto_produk')->store("mitra-products/{$mitra->id}", 'public');
            $product->update(['image_url' => $path]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Product photo upload failed. Please retry.',
            ], 500);
        }

        $this->deleteLocalPhoto($old);

        return response()->json([
            'success' => true,
            'message' => 'Product photo updated successfully.',
            'data' => new MitraProductResource($product->refresh()),
        ]);
    }

    /**
     * PATCH /api/merchant/products/{id} — edit produk milik sendiri.
     * Snapshot: voucher batch yang sudah terbit tidak ikut berubah.
     */
    public function update(UpdateMitraProductRequest $request, int $id): JsonResponse
    {
        $mitra = $this->ownMitra();

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $product = MitraProduct::where('id', $id)
            ->where('mitra_profile_id', $mitra->id)
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $product->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => new MitraProductResource($product->refresh()),
        ]);
    }

    /**
     * DELETE /api/merchant/products/{id} — nonaktifkan (soft) agar
     * riwayat voucher tidak yatim. Hapus permanen hanya bila belum
     * pernah dipakai di vouchers.
     */
    public function destroy(int $id): JsonResponse
    {
        $mitra = $this->ownMitra();

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $product = MitraProduct::where('id', $id)
            ->where('mitra_profile_id', $mitra->id)
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        if ($product->vouchers()->exists()) {
            $product->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Product has funded vouchers; deactivated instead of deleted.',
                'data' => new MitraProductResource($product->refresh()),
            ]);
        }

        $old = $product->image_url;
        $product->delete();
        $this->deleteLocalPhoto($old);

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
            'data' => null,
        ]);
    }

    private function ownMitra(): ?MitraProfile
    {
        return MitraProfile::where('user_id', Auth::id())->first();
    }

    /**
     * Hapus file foto lokal (disk path). URL eksternal tidak dihapus.
     */
    private function deleteLocalPhoto(?string $raw): void
    {
        if (! MitraProduct::isLocalPath($raw)) {
            return;
        }

        $path = ltrim((string) $raw, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable) {
            // Best-effort: file hilang tidak boleh menggagalkan request.
        }
    }
}
