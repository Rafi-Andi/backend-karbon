<?php

namespace App\Support;

use App\Services\CloudinaryService;
use Illuminate\Support\Facades\Storage;

/**
 * Satu tempat untuk memetakan nilai gambar tersimpan ke URL siap tampil.
 *
 * Nilai yang didukung:
 * - URL absolut (http/https) — dikembalikan apa adanya (mis. Cloudinary URL penuh / eksternal).
 * - "cloudinary:{public_id}" — dibangun jadi URL delivery Cloudinary dari config.
 * - "storage/..." atau path lokal lama — dipetakan via Storage::url().
 */
class ImageUrl
{
    public static function resolve(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        if (str_starts_with($raw, CloudinaryService::PUBLIC_ID_PREFIX)) {
            $publicId = substr($raw, strlen(CloudinaryService::PUBLIC_ID_PREFIX));

            return CloudinaryService::fromConfig()->publicUrl($publicId);
        }

        $path = ltrim($raw, '/');

        // Sudah berbentuk URL publik (/storage/...) — pakai apa adanya.
        if (str_starts_with($path, 'storage/')) {
            return '/'.$path;
        }

        return Storage::url($path);
    }

    /**
     * True bila nilai berupa path lokal (bukan URL eksternal / Cloudinary).
     */
    public static function isLocalPath(?string $raw): bool
    {
        if ($raw === null || trim($raw) === '') {
            return false;
        }

        $raw = trim($raw);

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return false;
        }

        return ! str_starts_with($raw, CloudinaryService::PUBLIC_ID_PREFIX);
    }
}
