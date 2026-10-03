<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WargaProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'level',
        'xp',
        'eco_points',
        'streak_days',
        'last_mission_at',
        'total_distance_km',
        'total_waste_kg',
        'total_carbon_saved_kg',
    ];

    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'eco_points' => 'integer',
            'streak_days' => 'integer',
            'last_mission_at' => 'datetime',
            'total_distance_km' => 'decimal:2',
            'total_waste_kg' => 'decimal:2',
            'total_carbon_saved_kg' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
