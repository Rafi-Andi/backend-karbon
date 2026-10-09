<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
     * DB menyimpan disk path lokal (mis. mitra-products/3/x.jpg) atau
     * URL eksternal. Path lokal dipetakan via Storage::url agar
     * konsisten dengan foto KTP/NIB/toko saat register.
     */
    public static function publicImageUrl(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        $path = ltrim($raw, '/');

        // Sudah berbentuk URL publik (/storage/...) — pakai apa adanya.
        if (str_starts_with($path, 'storage/')) {
            return '/'.$path;
        }

        return Storage::url($path);
    }

    public static function isLocalPath(?string $raw): bool
    {
        if ($raw === null || trim($raw) === '') {
            return false;
        }

        $raw = trim($raw);

        return ! str_starts_with($raw, 'http://') && ! str_starts_with($raw, 'https://');
    }
}
