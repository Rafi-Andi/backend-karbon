<?php

namespace Tests\Feature;

use App\Models\Disbursement;
use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class XenditPayoutWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.xendit.callback_token', 'webhook-test-token');
    }

    private function makeMitra(User $user, array $profile = []): MitraProfile
    {
        return MitraProfile::create(array_merge([
            'user_id' => $user->id,
            'nama_usaha' => 'Warung Test',
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Warung Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ], $profile));
    }

    private function makeVoucher(MitraProfile $mitra): Voucher
    {
        return Voucher::create([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Voucher',
            'description' => 'Desc',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function makeWarga(): User
    {
        $user = User::factory()->create(['role' => 'warga']);
        WargaProfile::create([
            'user_id' => $user->id,
            'level' => 'Earth Newbie',
            'xp' => 0,
            'eco_points' => 1000,
            'streak_days' => 0,
        ]);

        return $user;
    }

    private function makePendingDisbursement(MitraProfile $mitra): Disbursement
    {
        $warga = $this->makeWarga();
        $voucher = $this->makeVoucher($mitra);
        $claim = VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => 'KBK-WH-001',
            'status' => 'used',
            'claimed_at' => now(),
            'used_at' => now(),
        ]);

        return Disbursement::create([
            'mitra_profile_id' => $mitra->id,
            'voucher_claim_id' => $claim->id,
            'xendit_disbursement_id' => 'po-pending-123',
            'payout_id' => 'po-pending-123',
            'reference_id' => 'KBK-CLAIM-1',
            'amount' => 20000,
            'bank_name' => 'Bank BCA',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'Warung Test',
            'status' => 'pending',
            'response_log' => [],
        ]);
    }

    public function test_webhook_succeeded_updates_status_and_balance(): void
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $disbursement = $this->makePendingDisbursement($mitra);

        $payload = json_encode([
            'event' => 'v3_payout.succeeded',
            'business_id' => '6018306aa16ad90cb3c43ba7',
            'created' => '2025-06-10T11:45:00Z',
            'data' => [
                'payout_id' => 'po-pending-123',
                'status' => 'SUCCEEDED',
                'reference_id' => 'KBK-CLAIM-1',
            ],
        ]);

        $response = $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => config('services.xendit.callback_token'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(204);

        // Check status updated
        $this->assertSame('completed', $disbursement->refresh()->status);

        // Check balance updated using direct DB query
        $balance = DB::table('mitra_profiles')->where('id', $mitra->id)->value('balance');
        $this->assertSame(20000, (int) $balance, 'Mitra balance should be incremented by 20000');
    }

    public function test_webhook_duplicate_succeeded_does_not_double_balance(): void
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $disbursement = $this->makePendingDisbursement($mitra);

        $payload = json_encode([
            'event' => 'v3_payout.succeeded',
            'business_id' => '6018306aa16ad90cb3c43ba7',
            'created' => '2025-06-10T11:45:00Z',
            'data' => [
                'payout_id' => 'po-pending-123',
                'status' => 'SUCCEEDED',
                'reference_id' => 'KBK-CLAIM-1',
            ],
        ]);

        $signature = config('services.xendit.callback_token');

        // First webhook
        $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        // Check balance after first webhook
        $balance1 = DB::table('mitra_profiles')->where('id', $mitra->id)->value('balance');
        $this->assertSame(20000, (int) $balance1, 'Mitra balance should be 20000 after first webhook');

        // Duplicate webhook (same event, same payout)
        $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        // Check balance after second webhook - should still be 20000 (not 40000)
        $balance2 = DB::table('mitra_profiles')->where('id', $mitra->id)->value('balance');
        $this->assertSame(20000, (int) $balance2, 'Mitra balance must NOT be doubled on duplicate webhook');
    }

    public function test_webhook_failed_marks_status(): void
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $disbursement = $this->makePendingDisbursement($mitra);

        $payload = json_encode([
            'event' => 'v3_payout.failed',
            'business_id' => '6018306aa16ad90cb3c43ba7',
            'created' => '2025-06-11T08:01:30Z',
            'data' => [
                'payout_id' => 'po-pending-123',
                'status' => 'FAILED',
                'reference_id' => 'KBK-CLAIM-1',
                'failure_code' => 'INVALID_DESTINATION',
            ],
        ]);

        $response = $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => config('services.xendit.callback_token'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(204);
        $this->assertSame('failed', $disbursement->refresh()->status);
        $this->assertSame('INVALID_DESTINATION', $disbursement->refresh()->failure_code);
    }

    public function test_webhook_reversed_marks_status(): void
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $disbursement = $this->makePendingDisbursement($mitra);

        $payload = json_encode([
            'event' => 'v3_payout.reversed',
            'business_id' => '6018306aa16ad90cb3c43ba7',
            'created' => '2025-06-12T10:00:00Z',
            'data' => [
                'payout_id' => 'po-pending-123',
                'status' => 'REVERSED',
                'reference_id' => 'KBK-CLAIM-1',
            ],
        ]);

        $response = $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => config('services.xendit.callback_token'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(204);
        $this->assertSame('reversed', $disbursement->refresh()->status);
    }

    public function test_webhook_invalid_signature_returns_401(): void
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $this->makePendingDisbursement($mitra);

        $payload = json_encode([
            'event' => 'v3_payout.succeeded',
            'data' => ['payout_id' => 'po-pending-123', 'reference_id' => 'KBK-CLAIM-1', 'status' => 'SUCCEEDED'],
        ]);

        $response = $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'invalid-signature',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(401);
    }

    public function test_webhook_unknown_payout_returns_404(): void
    {
        $payload = json_encode([
            'event' => 'v3_payout.succeeded',
            'data' => [
                'payout_id' => 'po-unknown-999',
                'status' => 'SUCCEEDED',
                'reference_id' => 'KBK-UNKNOWN',
            ],
        ]);

        $response = $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => config('services.xendit.callback_token'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(404);
    }

    public function test_webhook_invalid_event_returns_204(): void
    {
        $payload = json_encode([
            'event' => 'v3_payment.succeeded',
            'data' => ['payout_id' => 'po-123', 'status' => 'SUCCEEDED', 'reference_id' => 'KBK-1'],
        ]);

        $response = $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => config('services.xendit.callback_token'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(204);
    }
}
