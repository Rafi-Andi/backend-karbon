<?php

namespace App\Http\Resources;

use App\Models\MitraProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
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
            'expired_at' => $this->expired_at->format('Y-m-d'),
            'is_active' => $this->is_active,
            'mitra' => [
                'name' => $this->mitraProfile->user->name ?? null,
                'store_name' => $this->mitraProfile->nama_usaha ?? null,
                // Fallback ke domisili owner untuk data lama yang
                // usaha_kota-nya null (seeder era sebelum kolom lokasi usaha).
                'city' => $this->mitraProfile->usaha_kota
                    ?? $this->mitraProfile->user->kota
                    ?? null,
                'address' => [
                    'alamat' => $this->mitraProfile->alamat_usaha ?? null,
                    'kelurahan' => $this->mitraProfile->usaha_kelurahan
                        ?? $this->mitraProfile->user->kelurahan
                        ?? null,
                    'kecamatan' => $this->mitraProfile->usaha_kecamatan
                        ?? $this->mitraProfile->user->kecamatan
                        ?? null,
                    'kota' => $this->mitraProfile->usaha_kota
                        ?? $this->mitraProfile->user->kota
                        ?? null,
                    'provinsi' => $this->mitraProfile->usaha_provinsi ?? null,
                    'kode_pos' => $this->mitraProfile->usaha_kode_pos ?? null,
                ],
            ],
        ];
    }
}
