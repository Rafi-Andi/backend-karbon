<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'scope' => $this->resource['scope'],
            'timeframe' => $this->resource['timeframe'],
            'wilayah' => $this->resource['wilayah'] ?? null,
            'rankings' => LeaderboardEntryResource::collection($this->resource['rankings']),
            'current_user' => $this->resource['current_user'] ? [
                'rank' => $this->resource['current_user']->rank,
                'user_id' => $this->resource['current_user']->user_id,
                'name' => $this->resource['current_user']->name,
                'kota' => $this->resource['current_user']->kota ?? null,
                'kecamatan' => $this->resource['current_user']->kecamatan ?? null,
                'kelurahan' => $this->resource['current_user']->kelurahan ?? null,
                'rt' => $this->resource['current_user']->rt,
                'rw' => $this->resource['current_user']->rw,
                'xp' => $this->resource['current_user']->total_xp,
                'level' => $this->resource['current_user']->level ?? 'Earth Newbie',
            ] : null,
        ];
    }
}
