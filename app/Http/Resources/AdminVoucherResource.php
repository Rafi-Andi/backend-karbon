<?php

namespace App\Http\Resources;

use App\Models\MitraProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Reward (voucher) untuk layar Kelola Reward admin — termasuk yang
 * nonaktif/kedaluwarsa, plus hitungan klaim nyata dari tabel claims
 * (kolom vouchers.claimed_count bisa drift, jadi admin pakai angka ini).
 */
class AdminVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mitra_product_id' => $this->mitra_product_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category ?? 'kuliner',
            'image_url' => MitraProduct::publicImageUrl($this->image_url),
            'points_cost' => $this->points_cost,
            'rupiah_value' => $this->rupiah_value,
            'stock' => $this->stock,
            'claimed_count' => $this->claimed_count,
            // Diisi via withCount() di AdminVoucherController.
            'claims_count' => $this->claims_count ?? null,
            'used_claims_count' => $this->used_claims_count ?? null,
            'expired_at' => $this->expired_at?->format('Y-m-d'),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->format('Y-m-d\TH:i:s'),
            'mitra' => [
                'id' => $this->mitra_profile_id,
                'name' => $this->mitraProfile->user->name ?? null,
                'store_name' => $this->mitraProfile->nama_usaha ?? null,
                // Fallback ke domisili owner untuk data lama.
                'city' => $this->mitraProfile->usaha_kota
                    ?? $this->mitraProfile->user->kota
                    ?? null,
            ],
        ];
    }
}
