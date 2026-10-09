<?php

namespace App\Http\Resources;

use App\Models\MitraProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'claim_id' => $this->id,
            'qr_token' => $this->qr_token,
            'status' => $this->status,
            'claimed_at' => $this->claimed_at?->format('Y-m-d\TH:i:s'),
            'used_at' => $this->used_at?->format('Y-m-d\TH:i:s'),
            'voucher' => [
                'id' => $this->voucher->id ?? null,
                'title' => $this->voucher->title ?? null,
                'description' => $this->voucher->description ?? null,
                'image_url' => MitraProduct::publicImageUrl($this->voucher->image_url ?? null),
                'rupiah_value' => $this->voucher->rupiah_value ?? null,
                'expired_at' => $this->voucher->expired_at?->format('Y-m-d'),
            ],
            'mitra' => [
                'store_name' => $this->voucher->mitraProfile->nama_usaha ?? null,
                'name' => $this->voucher->mitraProfile->user->name ?? null,
                'city' => $this->voucher->mitraProfile->usaha_kota
                    ?? $this->voucher->mitraProfile->user->kota
                    ?? null,
                'address' => [
                    'alamat' => $this->voucher->mitraProfile->alamat_usaha ?? null,
                    'kelurahan' => $this->voucher->mitraProfile->usaha_kelurahan
                        ?? $this->voucher->mitraProfile->user->kelurahan
                        ?? null,
                    'kecamatan' => $this->voucher->mitraProfile->usaha_kecamatan
                        ?? $this->voucher->mitraProfile->user->kecamatan
                        ?? null,
                    'kota' => $this->voucher->mitraProfile->usaha_kota
                        ?? $this->voucher->mitraProfile->user->kota
                        ?? null,
                    'provinsi' => $this->voucher->mitraProfile->usaha_provinsi ?? null,
                    'kode_pos' => $this->voucher->mitraProfile->usaha_kode_pos ?? null,
                ],
            ],
        ];
    }
}
