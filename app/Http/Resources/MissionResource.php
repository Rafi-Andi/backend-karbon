<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'activity_type' => $this->activity_type,
            'xp_reward' => $this->xp_reward,
            'points_reward' => $this->points_reward,
            'target_distance_km' => $this->target_distance_km !== null
                ? (float) $this->target_distance_km
                : null,
            'icon' => $this->icon,
            'is_active' => (bool) ($this->is_active ?? true),
            'is_completed_today' => (bool) ($this->is_completed_today ?? false),
        ];
    }
}
