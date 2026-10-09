<?php

namespace Tests\Unit;

use App\Services\EcoRate;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EcoRateTest extends TestCase
{
    public function test_default_rate_40(): void
    {
        Config::set('eco.rupiah_per_point', 40);

        $this->assertSame(40, EcoRate::rupiahPerPoint());
        $this->assertSame(250, EcoRate::pointsForRupiah(10000));
        $this->assertSame(500, EcoRate::pointsForRupiah(20000));
        $this->assertSame(1250, EcoRate::pointsForRupiah(50000));
    }

    public function test_ceils_fraction(): void
    {
        Config::set('eco.rupiah_per_point', 40);

        // 10001 / 40 = 250.025 -> 251 (tidak boleh dibulatkan ke bawah).
        $this->assertSame(251, EcoRate::pointsForRupiah(10001));
    }

    public function test_zero_or_negative_returns_zero(): void
    {
        Config::set('eco.rupiah_per_point', 40);

        $this->assertSame(0, EcoRate::pointsForRupiah(0));
        $this->assertSame(0, EcoRate::pointsForRupiah(-500));
    }

    public function test_invalid_rate_falls_back_to_40(): void
    {
        Config::set('eco.rupiah_per_point', 0);

        $this->assertSame(40, EcoRate::rupiahPerPoint());
    }
}
