<?php

namespace Tests\Feature;

use App\Services\LevelService;
use Tests\TestCase;

class SeederLevelSyncTest extends TestCase
{
    /**
     * Nilai XP yang dipakai seeder harus selalu dipasangkan dengan label
     * dari LevelService::resolveLevel(), bukan hardcode. Regresi untuk bug
     * "Rafi Warrior 7 turun ke Keeper 5 setelah kuis".
     */
    public function test_seeded_xp_maps_to_expected_level(): void
    {
        $this->assertSame('Earth Keeper 5', LevelService::resolveLevel(1080));
        $this->assertSame('Earth Keeper 5', LevelService::resolveLevel(1180));
        $this->assertSame('Earth Keeper 5', LevelService::resolveLevel(830));
        $this->assertSame('Earth Newbie', LevelService::resolveLevel(0));
    }

    public function test_quiz_xp_increment_keeps_level_stable(): void
    {
        // Simulasi 1 jawaban kuis benar (+10 XP) untuk Rafi: level tidak loncat.
        $before = LevelService::resolveLevel(1080);
        $after = LevelService::resolveLevel(1090);

        $this->assertSame($before, $after);
    }
}
