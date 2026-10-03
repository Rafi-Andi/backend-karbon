<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserMission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mission_id',
        'proof_image_url',
        'proof_image_hash',
        'ai_gemini_response',
        'confidence_score',
        'status',
        'anti_fraud_flagged',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'ai_gemini_response' => 'array',
            'confidence_score' => 'decimal:2',
            'anti_fraud_flagged' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function mobilityLog(): HasOne
    {
        return $this->hasOne(MobilityLog::class);
    }
}
