<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    /**
     * Tier badge donatur berdasar total donasi lunas (IDR).
     * Dihitung on-the-fly agar tidak perlu tabel badge.
     */
    public const TIERS = [
        'Donatur Emas' => 1000000,
        'Donatur Perak' => 250000,
        'Donatur Perunggu' => 50000,
    ];

    protected $fillable = [
        'user_id',
        'campaign_id',
        'external_id',
        'xendit_invoice_id',
        'amount',
        'status',
        'payer_name',
        'payer_email',
        'payment_channel',
        'paid_at',
        'expires_at',
        'response_log',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'response_log' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DonationCampaign::class, 'campaign_id');
    }

    public static function tierForTotal(float $totalPaid): ?string
    {
        foreach (self::TIERS as $tier => $min) {
            if ($totalPaid >= $min) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * XP reward donasi lunas: amount/100, min 50, max 500.
     * Tanpa eco_points agar ekonomi voucher tidak jebol (anti pay-to-win).
     */
    public static function xpForAmount(float $amount): int
    {
        return min(500, max(50, (int) floor($amount / 100)));
    }
}
