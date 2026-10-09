<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'rank' => $this->resource->rank,
            'user_id' => $this->resource->user_id,
            'name' => $this->resource->name,
            'kota' => $this->resource->kota ?? null,
            'kecamatan' => $this->resource->kecamatan ?? null,
            'kelurahan' => $this->resource->kelurahan ?? null,
            'rt' => $this->resource->rt,
            'rw' => $this->resource->rw,
            'xp' => $this->resource->total_xp,
            'avatar' => $this->resource->avatar ?? null,
            'level' => $this->resource->level ?? 'Earth Newbie',
        ];
    }
}
