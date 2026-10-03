<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    use HasFactory;

    /**
     * Kategori voucher marketplace (sumber tunggal untuk validasi filter).
     *
     * @var array<int, string>
     */
    public const CATEGORIES = ['kuliner', 'sembako', 'fashion', 'jasa', 'donasi', 'transportasi'];

    protected $fillable = [
        'mitra_profile_id',
        'title',
        'description',
        'category',
        'image_url',
        'points_cost',
        'rupiah_value',
        'stock',
        'claimed_count',
        'expired_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rupiah_value' => 'decimal:2',
            'expired_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function mitraProfile(): BelongsTo
    {
        return $this->belongsTo(MitraProfile::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(VoucherClaim::class);
    }
}
