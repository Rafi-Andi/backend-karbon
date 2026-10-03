<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category ?? 'kuliner',
            'image_url' => $this->image_url,
            'points_cost' => $this->points_cost,
            'rupiah_value' => $this->rupiah_value,
            'stock' => $this->stock,
            'claimed_count' => $this->claimed_count,
            'expired_at' => $this->expired_at->format('Y-m-d'),
            'is_active' => $this->is_active,
            'mitra' => [
                'name' => $this->mitraProfile->user->name ?? null,
                'store_name' => $this->mitraProfile->nama_usaha ?? null,
            ],
        ];
    }
}
