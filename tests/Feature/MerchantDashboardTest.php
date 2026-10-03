<?php

namespace Tests\Feature;

use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeMitra(array $profile = []): User
    {
        $user = User::factory()->mitra()->create();

        MitraProfile::create(array_merge([
            'user_id' => $user->id,
            'nama_usaha' => 'Warung Test',
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Warung Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 150000,
        ], $profile));

        return $user->refresh();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/merchant/dashboard')->assertStatus(401);
        $this->patchJson('/api/merchant/status', ['is_open' => false])->assertStatus(401);
    }

    public function test_warga_returns_403(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);

        $this->actingAs($warga, 'sanctum')
            ->getJson('/api/merchant/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_verified_mitra_gets_dashboard_with_stats(): void
    {
        $mitra = $this->makeMitra();

        Voucher::create([
            'mitra_profile_id' => $mitra->mitraProfile->id,
            'title' => 'Voucher Test',
            'description' => 'Desc',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($mitra, 'sanctum')
            ->getJson('/api/merchant/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.store_name', 'Warung Test')
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.can_redeem', true)
            ->assertJsonPath('data.balance', '150000.00')
            ->assertJsonPath('data.stats.total_vouchers', 1)
            ->assertJsonPath('data.stats.active_vouchers', 1);
    }

    public function test_pending_mitra_cannot_redeem_but_still_200(): void
    {
        $mitra = $this->makeMitra(['status_verifikasi' => 'pending', 'is_active' => false]);

        $this->actingAs($mitra, 'sanctum')
            ->getJson('/api/merchant/dashboard')
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.can_redeem', false);
    }

    public function test_toggle_status(): void
    {
        $mitra = $this->makeMitra(['is_active' => true]);

        $this->actingAs($mitra, 'sanctum')
            ->patchJson('/api/merchant/status', ['is_open' => false])
            ->assertOk()
            ->assertJsonPath('data.is_open', false);

        $this->assertFalse($mitra->mitraProfile->refresh()->is_active);
    }

    public function test_toggle_validation(): void
    {
        $mitra = $this->makeMitra();

        $this->actingAs($mitra, 'sanctum')
            ->patchJson('/api/merchant/status', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
