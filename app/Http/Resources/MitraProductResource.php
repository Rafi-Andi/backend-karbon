<?php

namespace App\Http\Resources;

use App\Models\MitraProduct;
use App\Services\EcoRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MitraProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rupiah = (int) round((float) $this->rupiah_value);

        return [
            'id' => $this->id,
            'mitra_profile_id' => $this->mitra_profile_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category ?? 'kuliner',
            'image_url' => MitraProduct::publicImageUrl($this->image_url),
            'rupiah_value' => number_format((float) $this->rupiah_value, 2, '.', ''),
            // Preview poin dihitung dari rate server (bukan dari client).
            'points_preview' => EcoRate::pointsForRupiah($rupiah),
            'rupiah_per_point' => EcoRate::rupiahPerPoint(),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->format('Y-m-d\TH:i:s'),
        ];
    }
}
