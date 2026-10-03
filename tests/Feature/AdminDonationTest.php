<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDonationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeMitra(array $over = []): MitraProfile
    {
        $user = User::factory()->mitra()->create();

        return MitraProfile::create(array_merge([
            'user_id' => $user->id,
            'nama_usaha' => 'Warung '.$user->id,
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Warung Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ], $over));
    }

    private function makeFundedCampaign(float $collected): DonationCampaign
    {
        $campaign = DonationCampaign::create([
            'title' => 'CSR Test',
            'slug' => 'csr-test-'.str()->random(6),
            'description' => 'Desc',
            'target_amount' => 10000000,
            'collected_amount' => $collected,
            'status' => 'active',
        ]);

        return $campaign;
    }

    private function voucherPayload(array $over = []): array
    {
        $mitra = $this->makeMitra();

        return array_merge([
            'campaign_id' => $this->makeFundedCampaign(1000000)->id,
            'mitra_profile_id' => $mitra->id,
            'title' => 'Voucher Sembako CSR',
            'description' => 'Dibiayai donasi CSR.',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 10,
            'expired_at' => now()->addMonth()->toDateString(),
        ], $over);
    }

    public function test_admin_endpoints_require_admin(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);

        $this->getJson('/api/admin/donation-campaigns')->assertStatus(401);
        $this->postJson('/api/admin/donation-campaigns', [])->assertStatus(401);
        $this->postJson('/api/admin/vouchers', [])->assertStatus(401);

        $this->actingAs($warga, 'sanctum')->getJson('/api/admin/donation-campaigns')->assertStatus(403);
        $this->actingAs($warga, 'sanctum')->postJson('/api/admin/vouchers', [])->assertStatus(403);
    }

    public function test_create_campaign_success_and_validation(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/donation-campaigns', ['title' => 'x'])
            ->assertStatus(422);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/donation-campaigns', [
                'title' => 'CSR Hijau 2026',
                'description' => 'Dana voucher.',
                'target_amount' => 5000000,
                'status' => 'active',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('data.slug'));
        $this->assertDatabaseHas('donation_campaigns', ['title' => 'CSR Hijau 2026', 'status' => 'active']);
    }

    public function test_update_campaign_status(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(0);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/donation-campaigns/{$campaign->id}", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/donation-campaigns/9999', ['status' => 'closed'])
            ->assertStatus(404);
    }

    public function test_funded_voucher_success_debits_campaign(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(1000000);
        $mitra = $this->makeMitra();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_profile_id' => $mitra->id,
                'title' => 'Voucher CSR',
                'description' => 'Dibiayai donasi.',
                'points_cost' => 100,
                'rupiah_value' => 20000,
                'stock' => 10,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        // Needed = 10 x 20000 = 200000
        $this->assertSame('200000.00', $response->json('data.allocated_amount'));
        $this->assertSame('800000.00', $response->json('data.campaign_available'));

        $this->assertDatabaseHas('vouchers', ['id' => $response->json('data.voucher_id'), 'stock' => 10]);
        $this->assertDatabaseHas('voucher_fund_allocations', [
            'voucher_id' => $response->json('data.voucher_id'),
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_funded_voucher_insufficient_funds_rolls_back(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(50000); // only 50k
        $mitra = $this->makeMitra();
        $before = Voucher::count();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_profile_id' => $mitra->id,
                'title' => 'Voucher Mahal',
                'description' => 'x',
                'points_cost' => 100,
                'rupiah_value' => 20000,
                'stock' => 10, // needs 200000
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame($before, Voucher::count());
        $this->assertDatabaseMissing('voucher_fund_allocations', ['campaign_id' => $campaign->id]);
    }

    public function test_funded_voucher_rejects_unverified_mitra(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(1000000);
        $mitra = $this->makeMitra(['status_verifikasi' => 'pending', 'is_active' => false]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_profile_id' => $mitra->id,
                'title' => 'Voucher X',
                'description' => 'x',
                'points_cost' => 100,
                'rupiah_value' => 20000,
                'stock' => 1,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_full_flow_donation_to_voucher(): void
    {
        // Donatur membayar -> webhook -> admin belanjakan -> voucher ada stok
        $warga = User::factory()->create(['role' => 'warga']);
        \App\Models\WargaProfile::create([
            'user_id' => $warga->id, 'level' => 'Earth Newbie',
            'xp' => 0, 'eco_points' => 0, 'streak_days' => 0,
        ]);
        $admin = $this->makeAdmin();
        $campaign = DonationCampaign::create([
            'title' => 'CSR Penuh', 'slug' => 'csr-penuh', 'description' => 'd',
            'target_amount' => 1000000, 'status' => 'active',
        ]);
        $mitra = $this->makeMitra();

        $donation = Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-FULL-1', 'xendit_invoice_id' => 'inv-full-1',
            'amount' => 200000, 'status' => 'pending',
        ]);

        \Illuminate\Support\Facades\Config::set('services.xendit.callback_token', 'test-token');
        $payload = json_encode([
            'id' => 'inv-full-1', 'external_id' => 'DN-FULL-1',
            'status' => 'PAID', 'paid_amount' => 200000,
        ]);
        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(204);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_profile_id' => $mitra->id,
                'title' => 'Voucher Hasil Donasi',
                'description' => 'Dibiayai penuh donasi.',
                'points_cost' => 50,
                'rupiah_value' => 20000,
                'stock' => 10,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(201);

        $this->assertSame(0.0, $campaign->refresh()->availableAmount());
    }
}
