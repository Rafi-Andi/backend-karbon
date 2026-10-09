<?php

namespace App\Services;

class EcoRate
{
    /**
     * Rupiah per 1 eco point. Satu-satunya sumber kebenaran konversi.
     * Default 40 (Rp10.000 = 250 poin) mengikuti voucher existing agar
     * tidak inflasi: max ~355 poin/hari, kasual ~100-150/hari.
     */
    public static function rupiahPerPoint(): int
    {
        $rate = (int) config('eco.rupiah_per_point', 40);

        return $rate > 0 ? $rate : 40;
    }

    /**
     * Hitung points_cost dari nominal rupiah. Selalu ceil agar pecahan
     * tidak menguntungkan (mis. Rp10.001 / 40 = 251, bukan 250).
     */
    public static function pointsForRupiah(int $rupiah): int
    {
        if ($rupiah <= 0) {
            return 0;
        }

        return (int) ceil($rupiah / self::rupiahPerPoint());
    }
}
