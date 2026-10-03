<?php

namespace App\Http\Resources;

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
                'image_url' => $this->voucher->image_url ?? null,
                'rupiah_value' => $this->voucher->rupiah_value ?? null,
                'expired_at' => $this->voucher->expired_at?->format('Y-m-d'),
            ],
            'mitra' => [
                'store_name' => $this->voucher->mitraProfile->nama_usaha ?? null,
                'name' => $this->voucher->mitraProfile->user->name ?? null,
            ],
        ];
    }
}
