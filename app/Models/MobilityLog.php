<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobilityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_mission_id',
        'user_id',
        'start_point',
        'end_point',
        'route_coordinates',
        'distance_km',
        'co2_saved_grams',
        'duration_minutes',
        'transport_mode',
    ];

    protected function casts(): array
    {
        return [
            'start_point' => 'array',
            'end_point' => 'array',
            'route_coordinates' => 'array',
            'distance_km' => 'decimal:2',
            'co2_saved_grams' => 'decimal:2',
        ];
    }

    public function userMission(): BelongsTo
    {
        return $this->belongsTo(UserMission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
