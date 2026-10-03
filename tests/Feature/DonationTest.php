<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\User;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.xendit.mock', true);
        Config::set('services.xendit.key', '');
        Config::set('services.xendit.callback_token', 'test-token');
    }

    private function makeWarga(int $ecoPoints = 1000): User
    {
        $user = User::factory()->create(['role' => 'warga']);
        WargaProfile::create([
            'user_id' => $user->id,
            'level' => 'Earth Newbie',
            'xp' => 0,
            'eco_points' => $ecoPoints,
            'streak_days' => 0,
        ]);

        return $user;
    }

    private function makeCampaign(array $over = []): DonationCampaign
    {
        return DonationCampaign::create(array_merge([
            'title' => 'CSR Hijau Q4',
            'slug' => 'csr-hijau-q4-'.str()->random(6),
            'description' => 'Dana voucher untuk warga.',
            'target_amount' => 10000000,
            'status' => 'active',
            'started_at' => now()->subDay(),
        ], $over));
    }

    public function test_public_campaigns_only_open(): void
    {
        $open = $this->makeCampaign();
        $this->makeCampaign(['slug' => 'draft-x', 'status' => 'draft']);
        $this->makeCampaign(['slug' => 'closed-x', 'status' => 'closed']);

        $response = $this->getJson('/api/donation-campaigns')->assertOk()->assertJsonPath('success', true);

        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains($open->slug));
        $this->assertCount(1, $slugs);
    }

    public function test_campaign_detail_with_leaderboard(): void
    {
        $campaign = $this->makeCampaign();
        $warga = $this->makeWarga();

        $donation = Donation::create([
            'user_id' => $warga->id,
            'campaign_id' => $campaign->id,
            'external_id' => 'DN-TEST-1',
            'xendit_invoice_id' => 'inv-1',
            'amount' => 300000,
            'status' => 'paid',
            'payer_name' => 'Hendra',
            'paid_at' => now(),
        ]);
        $campaign->increment('collected_amount', 300000);

        $this->getJson("/api/donation-campaigns/{$campaign->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $campaign->slug)
            ->assertJsonPath('data.donor_count', 1)
            ->assertJsonPath('data.top_donors.0.tier', 'Donatur Perak')
            ->assertJsonPath('data.recent_donations.0.payer_name', 'Hendra');

        $this->getJson('/api/donation-campaigns/tidak-ada')->assertStatus(404);
    }

    public function test_create_requires_auth(): void
    {
        $campaign = $this->makeCampaign();

        $this->postJson('/api/donations', ['campaign_id' => $campaign->id, 'amount' => 50000])
            ->assertStatus(401);
    }

    public function test_create_validates_min_amount(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign();

        $this->actingAs($warga, 'sanctum')
            ->postJson('/api/donations', ['campaign_id' => $campaign->id, 'amount' => 5000])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_create_rejects_closed_campaign(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign(['status' => 'closed']);

        $this->actingAs($warga, 'sanctum')
            ->postJson('/api/donations', ['campaign_id' => $campaign->id, 'amount' => 50000])
            ->assertStatus(422);
    }

    public function test_create_success_returns_invoice_url(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign();

        $response = $this->actingAs($warga, 'sanctum')
            ->postJson('/api/donations', ['campaign_id' => $campaign->id, 'amount' => 50000])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertStringStartsWith('https://', $response->json('data.invoice_url'));
        $this->assertStringStartsWith('DN-', $response->json('data.external_id'));
        $this->assertNotNull($response->json('data.expires_at'));

        $this->assertDatabaseHas('donations', [
            'id' => $response->json('data.donation_id'),
            'status' => 'pending',
        ]);
    }

    public function test_my_donations_with_tier(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign();

        Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-MY-1', 'amount' => 60000,
            'status' => 'paid', 'payer_name' => 'Warga', 'paid_at' => now(),
        ]);

        $this->actingAs($warga, 'sanctum')
            ->getJson('/api/user/my-donations')
            ->assertOk()
            ->assertJsonPath('data.tier', 'Donatur Perunggu')
            ->assertJsonPath('data.lifetime_total', '60000.00');
    }

    public function test_cancel_pending_expires_invoice(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign();

        $donation = Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-CX-1', 'xendit_invoice_id' => 'inv-mock-x1',
            'amount' => 50000, 'status' => 'pending',
        ]);

        $this->actingAs($warga, 'sanctum')
            ->postJson("/api/donations/{$donation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertSame('expired', $donation->refresh()->status);
    }

    public function test_cancel_paid_returns_409_and_foreign_returns_404(): void
    {
        $warga = $this->makeWarga();
        $other = $this->makeWarga();
        $campaign = $this->makeCampaign();

        $paid = Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-CX-2', 'amount' => 50000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->actingAs($warga, 'sanctum')
            ->postJson("/api/donations/{$paid->id}/cancel")
            ->assertStatus(409);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/donations/{$paid->id}/cancel")
            ->assertStatus(404);
    }

    public function test_webhook_paid_credits_campaign_and_xp_without_points(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign();

        $donation = Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-WH-1', 'xendit_invoice_id' => 'inv-wh-1',
            'amount' => 50000, 'status' => 'pending',
        ]);

        $payload = json_encode([
            'id' => 'inv-wh-1',
            'external_id' => 'DN-WH-1',
            'status' => 'PAID',
            'paid_amount' => 50000,
            'payment_channel' => 'QRIS',
            'paid_at' => '2026-09-08T10:00:00Z',
        ]);

        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(204);

        $this->assertSame('paid', $donation->refresh()->status);
        $this->assertSame('QRIS', $donation->refresh()->payment_channel);
        $this->assertSame(50000, (int) DB::table('donation_campaigns')->where('id', $campaign->id)->value('collected_amount'));

        // XP reward = 50000/100 = 500 (cap), eco_points untouched
        $profile = WargaProfile::where('user_id', $warga->id)->first();
        $this->assertSame(500, $profile->xp);
        $this->assertSame(1000, $profile->eco_points);
    }

    public function test_webhook_duplicate_paid_does_not_double(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign();

        Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-WH-2', 'xendit_invoice_id' => 'inv-wh-2',
            'amount' => 20000, 'status' => 'pending',
        ]);

        $payload = json_encode([
            'id' => 'inv-wh-2', 'external_id' => 'DN-WH-2',
            'status' => 'PAID', 'paid_amount' => 20000,
        ]);
        $server = ['HTTP_X-CALLBACK-TOKEN' => 'test-token', 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], $server, $payload)->assertStatus(204);
        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], $server, $payload)->assertStatus(204);

        $this->assertSame(20000, (int) DB::table('donation_campaigns')->where('id', $campaign->id)->value('collected_amount'));
        $this->assertSame(200, WargaProfile::where('user_id', $warga->id)->first()->xp);
    }

    public function test_webhook_expired_and_auth_failures(): void
    {
        $warga = $this->makeWarga();
        $campaign = $this->makeCampaign();

        $donation = Donation::create([
            'user_id' => $warga->id, 'campaign_id' => $campaign->id,
            'external_id' => 'DN-WH-3', 'xendit_invoice_id' => 'inv-wh-3',
            'amount' => 20000, 'status' => 'pending',
        ]);

        $payload = json_encode(['id' => 'inv-wh-3', 'external_id' => 'DN-WH-3', 'status' => 'EXPIRED']);

        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(204);
        $this->assertSame('expired', $donation->refresh()->status);

        // Bad token
        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'wrong',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(401);

        // Unknown invoice
        $unknown = json_encode(['id' => 'inv-nope', 'external_id' => 'DN-NOPE', 'status' => 'PAID']);
        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $unknown)->assertStatus(404);

        // Missing status
        $bad = json_encode(['id' => 'inv-wh-3']);
        $this->call('POST', '/api/webhooks/xendit/invoice', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $bad)->assertStatus(400);
    }
}
