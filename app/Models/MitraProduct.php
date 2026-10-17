<?php

namespace App\Models;

use App\Support\ImageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MitraProduct extends Model
{
    protected $fillable = [
        'mitra_profile_id',
        'title',
        'description',
        'category',
        'image_url',
        'rupiah_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rupiah_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function mitraProfile(): BelongsTo
    {
        return $this->belongsTo(MitraProfile::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * Petakan image_url ke URL publik siap tampil.
     * DB menyimpan disk path lokal (mis. mitra-products/3/x.jpg),
     * penanda Cloudinary (`cloudinary:{public_id}`), atau URL eksternal.
     * Lihat App\Support\ImageUrl.
     */
    public static function publicImageUrl(?string $raw): ?string
    {
        return ImageUrl::resolve($raw);
    }

    public static function isLocalPath(?string $raw): bool
    {
        return ImageUrl::isLocalPath($raw);
    }
}
