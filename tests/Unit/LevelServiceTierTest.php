<?php

namespace Tests\Unit;

use App\Services\LevelService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LevelServiceTierTest extends TestCase
{
    public static function tierProvider(): array
    {
        return [
            'nol' => [0, 'newbie'],
            'batas newbie' => [499, 'newbie'],
            'masuk keeper' => [500, 'keeper'],
            'atas keeper' => [1699, 'keeper'],
            'masuk warrior' => [1700, 'warrior'],
            'warrior besar' => [10800, 'warrior'],
        ];
    }

    #[DataProvider('tierProvider')]
    public function test_tier_key_for_xp(int $xp, string $expected): void
    {
        $this->assertSame($expected, LevelService::getTierKeyForXp($xp));
    }

    public function test_get_tiers_newbie_hanya_satu_terbuka(): void
    {
        $tiers = LevelService::getTiers(100);

        $this->assertCount(3, $tiers);
        $this->assertTrue($tiers[0]['is_unlocked']);
        $this->assertTrue($tiers[0]['is_current']);
        $this->assertFalse($tiers[1]['is_unlocked']);
        $this->assertFalse($tiers[2]['is_unlocked']);
        $this->assertSame('assets/images/level_newbie.png', $tiers[0]['asset']);
    }

    public function test_get_tiers_keeper_dua_terbuka(): void
    {
        $tiers = LevelService::getTiers(800);

        $this->assertTrue($tiers[0]['is_unlocked']);
        $this->assertTrue($tiers[1]['is_unlocked']);
        $this->assertTrue($tiers[1]['is_current']);
        $this->assertFalse($tiers[2]['is_unlocked']);
        $this->assertSame(500, $tiers[1]['min_xp']);
    }

    public function test_get_tiers_warrior_semua_terbuka(): void
    {
        $tiers = LevelService::getTiers(2500);

        $this->assertTrue($tiers[0]['is_unlocked']);
        $this->assertTrue($tiers[1]['is_unlocked']);
        $this->assertTrue($tiers[2]['is_unlocked']);
        $this->assertTrue($tiers[2]['is_current']);
    }
}
