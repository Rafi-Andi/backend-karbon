<?php

namespace App\Http\Resources;

use App\Services\LevelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->resource['profile'];
        $dailyMissions = $this->resource['daily_missions'];
        $leaderboardPreview = $this->resource['leaderboard_preview'];

        return [
            'user' => [
                'name' => $this->resource['name'],
                'rt' => $this->resource['rt'] ?? null,
                'rw' => $this->resource['rw'] ?? null,
                'kelurahan' => $this->resource['kelurahan'] ?? null,
                'level' => $profile->level,
                'xp' => $profile->xp,
                'xp_max' => LevelService::getXpMaxForLevel($profile->level),
                'xp_percentage' => LevelService::calculateXpPercentage($profile->xp, $profile->level),
                'eco_points' => $profile->eco_points,
                'streak_days' => $profile->streak_days,
                'rank_percentage' => $this->resource['rank_percentage'] ?? 0,
                'total_distance_km' => $profile->total_distance_km,
                'total_waste_kg' => $profile->total_waste_kg,
                'total_carbon_saved_kg' => $profile->total_carbon_saved_kg,
            ],
            'daily_missions' => MissionResource::collection($dailyMissions),
            'leaderboard_preview' => LeaderboardEntryResource::collection($leaderboardPreview),
        ];
    }
}
