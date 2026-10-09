<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\MitraProduct;
use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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

    private function makeProduct(?MitraProfile $mitra = null, array $over = []): MitraProduct
    {
        $mitra ??= $this->makeMitra();

        return MitraProduct::create(array_merge([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Kopi Susu CSR',
            'description' => 'Dibiayai donasi CSR.',
            'category' => 'kuliner',
            'rupiah_value' => 20000,
            'is_active' => true,
        ], $over));
    }

    private function voucherPayload(array $over = []): array
    {
        $product = $this->makeProduct();

        return array_merge([
            'campaign_id' => $this->makeFundedCampaign(1000000)->id,
            'mitra_product_id' => $product->id,
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
        $product = $this->makeProduct();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_product_id' => $product->id,
                'stock' => 10,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        // Needed = 10 x 20000 = 200000, points = ceil(20000/40) = 500.
        $this->assertSame('200000.00', $response->json('data.allocated_amount'));
        $this->assertSame('800000.00', $response->json('data.campaign_available'));
        $this->assertSame(500, $response->json('data.points_cost'));
        $this->assertSame(40, $response->json('data.rupiah_per_point'));

        $this->assertDatabaseHas('vouchers', [
            'id' => $response->json('data.voucher_id'),
            'stock' => 10,
            'points_cost' => 500,
            'rupiah_value' => 20000,
            'mitra_product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('voucher_fund_allocations', [
            'voucher_id' => $response->json('data.voucher_id'),
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_funded_voucher_snapshot_copies_product_photo(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(1000000);
        $product = $this->makeProduct(null, [
            'image_url' => 'mitra-products/1/kopi.jpg',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_product_id' => $product->id,
                'stock' => 1,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(201);

        $voucherId = $response->json('data.voucher_id');
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucherId,
            'image_url' => 'mitra-products/1/kopi.jpg',
        ]);

        // Marketplace memetakan path lokal ke URL publik.
        $warga = User::factory()->create(['role' => 'warga']);
        $list = $this->actingAs($warga, 'sanctum')
            ->getJson('/api/vouchers')
            ->assertOk()
            ->json('data');
        $found = collect($list)->firstWhere('id', $voucherId);
        $this->assertSame('/storage/mitra-products/1/kopi.jpg', $found['image_url']);
    }

    public function test_funded_voucher_ignores_client_points_cost(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(1000000);
        $product = $this->makeProduct();

        // points_cost ngawur dari client harus diabaikan (anti-inflasi).
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_product_id' => $product->id,
                'points_cost' => 1,
                'stock' => 1,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(201);

        $this->assertSame(500, $response->json('data.points_cost'));
    }

    public function test_funded_voucher_snapshot_not_live(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(1000000);
        $product = $this->makeProduct(null, ['rupiah_value' => 10000]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_product_id' => $product->id,
                'stock' => 2,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(201);

        $voucherId = $response->json('data.voucher_id');

        // Mitra ubah harga setelah funding — batch lama tidak ikut berubah.
        $product->update(['rupiah_value' => 50000, 'title' => 'Harga Baru']);

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucherId,
            'rupiah_value' => 10000,
            'points_cost' => 250,
            'title' => 'Kopi Susu CSR',
        ]);
    }

    public function test_funded_voucher_insufficient_funds_rolls_back(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(50000); // only 50k
        $product = $this->makeProduct();
        $before = Voucher::count();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_product_id' => $product->id,
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
        $product = $this->makeProduct($mitra);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_product_id' => $product->id,
                'stock' => 1,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_funded_voucher_rejects_inactive_product(): void
    {
        $admin = $this->makeAdmin();
        $campaign = $this->makeFundedCampaign(1000000);
        $product = $this->makeProduct(null, ['is_active' => false]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/vouchers', [
                'campaign_id' => $campaign->id,
                'mitra_product_id' => $product->id,
                'stock' => 1,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_full_flow_donation_to_voucher(): void
    {
        // Donatur membayar -> webhook -> admin belanjakan -> voucher ada stok
        $warga = User::factory()->create(['role' => 'warga']);
        WargaProfile::create([
            'user_id' => $warga->id, 'level' => 'Earth Newbie',
            'xp' => 0, 'eco_points' => 0, 'streak_days' => 0,
        ]);
        $admin = $this->makeAdmin();
        $campaign = DonationCampaign::create([
            'title' => 'CSR Penuh', 'slug' => 'csr-penuh', 'description' => 'd',
            'target_amount' => 1000000, 'status' => 'active',
        ]);
        $mitra = $this->makeMitra();
        $product = $this->makeProduct($mitra);

        $donation = Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-FULL-1', 'xendit_invoice_id' => 'inv-full-1',
            'amount' => 200000, 'status' => 'pending',
        ]);

        Config::set('services.xendit.callback_token', 'test-token');
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
                'mitra_product_id' => $product->id,
                'stock' => 10,
                'expired_at' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(201);

        $this->assertSame(0.0, $campaign->refresh()->availableAmount());
    }
}
