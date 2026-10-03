<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DonationCampaign extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'image_url',
        'target_amount',
        'collected_amount',
        'allocated_amount',
        'status',
        'started_at',
        'ended_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'campaign_id');
    }

    public function fundAllocations(): HasMany
    {
        return $this->hasMany(VoucherFundAllocation::class, 'campaign_id');
    }

    /**
     * Dana siap dialokasikan = terkumpul - sudah dialokasikan.
     */
    public function availableAmount(): float
    {
        return max(0, (float) $this->collected_amount - (float) $this->allocated_amount);
    }

    public function progressPercent(): float
    {
        $target = (float) $this->target_amount;

        if ($target <= 0) {
            return 0.0;
        }

        return round(((float) $this->collected_amount / $target) * 100, 2);
    }

    public function isOpen(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();

        if ($this->started_at && $now->lt($this->started_at)) {
            return false;
        }

        if ($this->ended_at && $now->gt($this->ended_at)) {
            return false;
        }

        return true;
    }
}
