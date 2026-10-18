<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris penukar untuk layar Detail Reward admin: siapa warga yang
 * menukarkan, kontak, wilayah, dan status pemakaian.
 */
class AdminVoucherClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'claim_id' => $this->id,
            'qr_token' => $this->qr_token,
            'status' => $this->status,
            'claimed_at' => $this->claimed_at?->format('Y-m-d\TH:i:s'),
            'used_at' => $this->used_at?->format('Y-m-d\TH:i:s'),
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user->name ?? null,
                'phone' => $this->user->phone ?? null,
                'rt' => $this->user->rt ?? null,
                'rw' => $this->user->rw ?? null,
            ],
        ];
    }
}
