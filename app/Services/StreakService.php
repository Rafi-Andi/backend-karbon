<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;

class StreakService
{
    public const TZ = 'Asia/Jakarta';

    /**
     * Compute next streak_days based on WIB date comparison.
     * - null last_mission_at => 1
     * - last is yesterday WIB => +1
     * - last is today WIB => keep (idempotent)
     * - gap >1 day => reset 1
     */
    public static function nextStreak(?IlluminateCarbon $lastMissionAt, int $currentStreak): int
    {
        if (! $lastMissionAt) {
            return 1;
        }

        $tz = self::TZ;
        $lastDate = $lastMissionAt->copy()->timezone($tz)->toDateString();
        $today = Carbon::now($tz)->toDateString();
        $yesterday = Carbon::now($tz)->subDay()->toDateString();

        if ($lastDate === $today) {
            return $currentStreak; // already counted today
        }

        if ($lastDate === $yesterday) {
            return $currentStreak + 1;
        }

        return 1;
    }

    public static function isTodayWib(?IlluminateCarbon $date): bool
    {
        if (! $date) {
            return false;
        }

        return $date->copy()->timezone(self::TZ)->toDateString() === Carbon::now(self::TZ)->toDateString();
    }
}
