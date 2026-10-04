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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoucherPayoutIntegrationTest extends TestCase
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

    private function makeMitra(User $user, array $over = []): MitraProfile
    {
        return MitraProfile::create(array_merge([
            'user_id' => $user->id,
            'nama_usaha' => 'Warung Test',
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Warung Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ], $over));
    }

    private function makeVoucher(MitraProfile $mitra, array $over = []): Voucher
    {
        return Voucher::create(array_merge([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Voucher 20k',
            'description' => 'Desc',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ], $over));
    }

    public function test_claim_then_mock_redeem_full_flow(): void
    {
        $warga = $this->makeWarga(1000);
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);

        // Claim via API
        $claimRes = $this->actingAs($warga, 'sanctum')
            ->postJson('/api/vouchers/claim', ['voucher_id' => $voucher->id])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $qr = $claimRes->json('data.qr_token');
        $claimId = $claimRes->json('data.claim_id');
        $this->assertNotEmpty($qr);

        // Verify claim stored as claimed
        $this->assertDatabaseHas('voucher_claims', ['id' => $claimId, 'qr_token' => $qr, 'status' => 'claimed']);

        // Redeem mock (XENDIT_MOCK=true => immediate completed + balance increment)
        $redeemRes = $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => $qr])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $redeemRes->json('data.disbursement_status'));
        $this->assertSame('20000.00', $redeemRes->json('data.amount'));
        $this->assertStringStartsWith('po-mock-', $redeemRes->json('data.xendit_payout_id'));
        $this->assertSame('KBK-CLAIM-'.$claimId, $redeemRes->json('data.reference_id'));

        // Claim should be used
        $this->assertSame('used', VoucherClaim::find($claimId)->status);

        // Disbursement stored correctly with both payout_id columns
        $disb = Disbursement::where('voucher_claim_id', $claimId)->first();
        $this->assertNotNull($disb);
        $this->assertSame($disb->payout_id, $disb->xendit_disbursement_id);
        $this->assertSame('completed', $disb->status);
        $this->assertSame('SUCCEEDED', $disb->raw_status);
        $this->assertSame('KBK-CLAIM-'.$claimId, $disb->reference_id);

        // Balance incremented immediately in mock mode
        $this->assertSame('20000.00', number_format((float) $mitra->refresh()->balance, 2, '.', ''));

        // Double redeem must 409
        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => $qr])
            ->assertStatus(409);
    }

    public function test_duplicate_error_returns_actionable_502_not_retry(): void
    {
        Config::set('services.xendit.mock', false);
        Config::set('services.xendit.key', 'test-key');

        Http::fake([
            'api.xendit.co/v3/payouts' => Http::response([
                'error_code' => 'DUPLICATE_ERROR',
                'message' => 'Existing payout record with same idempotency key but different request was matched in our records',
            ], 409),
        ]);

        $warga = $this->makeWarga(1000);
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);

        $claimRes = $this->actingAs($warga, 'sanctum')
            ->postJson('/api/vouchers/claim', ['voucher_id' => $voucher->id])
            ->assertStatus(201);

        $qr = $claimRes->json('data.qr_token');
        $claimId = $claimRes->json('data.claim_id');

        $res = $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => $qr])
            ->assertStatus(502);

        // Pesan actionable (bukan "Please retry") + reference untuk rekonsiliasi.
        $res->assertJsonPath('success', false);
        $this->assertStringContainsString('Do not retry', $res->json('message'));
        $this->assertStringContainsString('KBK-CLAIM-'.$claimId, $res->json('message'));
        $this->assertStringContainsString('DUPLICATE_ERROR', $res->json('error'));

        // Rollback total: klaim tetap claimed, tanpa disbursement.
        $this->assertSame('claimed', VoucherClaim::find($claimId)->status);
        $this->assertSame(0, Disbursement::where('voucher_claim_id', $claimId)->count());
    }

    public function test_real_payout_pending_then_webhook_succeeded_credits_balance(): void
    {
        Config::set('services.xendit.mock', false);
        Config::set('services.xendit.key', 'test-key');

        Http::fake([
            'api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'po-real-accepted-123',
                'status' => 'ACCEPTED',
                'reference_id' => 'KBK-CLAIM-1',
                'source_currency' => 'IDR',
                'source_amount' => 20000,
                'destination_currency' => 'IDR',
                'destination_amount' => 20000,
            ], 200),
        ]);

        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);
        $claim = VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => 'KBK-PEND-1',
            'status' => 'claimed',
            'claimed_at' => now(),
        ]);

        $res = $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-PEND-1'])
            ->assertOk();

        $this->assertSame('pending', $res->json('data.disbursement_status'));
        $this->assertSame('0.00', $res->json('data.new_balance'));
        $this->assertSame(0.0, (float) $mitra->refresh()->balance);

        $disb = Disbursement::where('voucher_claim_id', $claim->id)->first();
        $this->assertSame('pending', $disb->status);
        $this->assertSame('ACCEPTED', $disb->raw_status);

        // Webhook succeeded should credit
        $payload = json_encode([
            'event' => 'v3_payout.succeeded',
            'data' => [
                'payout_id' => 'po-real-accepted-123',
                'status' => 'SUCCEEDED',
                'reference_id' => $disb->reference_id,
            ],
        ]);

        $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(204);

        $this->assertSame('completed', $disb->refresh()->status);
        $this->assertSame(20000, (int) DB::table('mitra_profiles')->where('id', $mitra->id)->value('balance'));
    }

    public function test_webhook_reversed_debits_previously_completed_balance(): void
    {
        Config::set('services.xendit.mock', true);
        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);
        $claim = VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => 'KBK-REV-1',
            'status' => 'claimed',
            'claimed_at' => now(),
        ]);

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-REV-1'])
            ->assertOk();

        $disb = Disbursement::where('voucher_claim_id', $claim->id)->first();
        $this->assertSame('completed', $disb->status);
        $this->assertSame(20000, (int) DB::table('mitra_profiles')->where('id', $mitra->id)->value('balance'));

        // Simulate reversal
        $payload = json_encode([
            'event' => 'v3_payout.reversed',
            'data' => [
                'payout_id' => $disb->payout_id,
                'status' => 'REVERSED',
                'reference_id' => $disb->reference_id,
            ],
        ]);

        $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(204);

        $this->assertSame('reversed', $disb->refresh()->status);
        $this->assertSame(0, (int) DB::table('mitra_profiles')->where('id', $mitra->id)->value('balance'));
    }

    public function test_unsupported_bank_returns_422_not_502(): void
    {
        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser, ['nama_bank' => 'Bank Arta Kedaton']); // not in allowlist
        $voucher = $this->makeVoucher($mitra);
        VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => 'KBK-BANK-1',
            'status' => 'claimed',
            'claimed_at' => now(),
        ]);

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-BANK-1'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('disbursements', ['voucher_claim_id' => VoucherClaim::where('qr_token', 'KBK-BANK-1')->first()->id]);
    }

    public function test_webhook_plain_token_required_not_hmac(): void
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $warga = $this->makeWarga();
        $voucher = $this->makeVoucher($mitra);
        $claim = VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => 'KBK-TOK-1',
            'status' => 'claimed',
            'claimed_at' => now(),
        ]);
        $disb = Disbursement::create([
            'mitra_profile_id' => $mitra->id,
            'voucher_claim_id' => $claim->id,
            'xendit_disbursement_id' => 'po-123',
            'payout_id' => 'po-123',
            'reference_id' => 'KBK-CLAIM-'.$claim->id,
            'amount' => 20000,
            'bank_name' => 'Bank BCA',
            'bank_account_number' => '123',
            'bank_account_name' => 'Test',
            'status' => 'pending',
            'response_log' => [],
        ]);

        $payload = json_encode([
            'event' => 'v3_payout.succeeded',
            'data' => ['payout_id' => 'po-123', 'status' => 'SUCCEEDED', 'reference_id' => $disb->reference_id],
        ]);

        // HMAC should be rejected (401) after fix
        $hmac = hash_hmac('sha256', $payload, 'test-token');
        $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => $hmac,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(401);

        // Plain token should succeed
        $this->call('POST', '/api/webhooks/xendit/payout', [], [], [], [
            'HTTP_X-CALLBACK-TOKEN' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(204);
    }

    public function test_merchant_dashboard_shows_payout_details(): void
    {
        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);
        $claim = VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => 'KBK-DASH-1',
            'status' => 'claimed',
            'claimed_at' => now(),
        ]);

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-DASH-1'])
            ->assertOk();

        $this->actingAs($mitraUser, 'sanctum')
            ->getJson('/api/merchant/dashboard')
            ->assertOk()
            ->assertJsonPath('data.store_name', 'Warung Test')
            ->assertJsonPath('data.can_redeem', true)
            ->assertJsonStructure(['data' => ['recent_disbursements' => [['payout_id', 'reference_id', 'status', 'raw_status']]]]);
    }
}
