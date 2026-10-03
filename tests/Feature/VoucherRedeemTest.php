<?php

namespace Tests\Feature;

use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoucherRedeemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Kunci mode mock agar test deterministik dan tidak pernah menyentuh
        // API Xendit asli, apa pun isi XENDIT_* di .env lokal developer.
        Config::set('services.xendit.mock', true);
        Config::set('services.xendit.key', '');
    }

    private function makeMitra(User $user, array $profile = []): MitraProfile
    {
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
        ], $profile));
    }

    private function makeVoucher(MitraProfile $mitra, array $over = []): Voucher
    {
        return Voucher::create(array_merge([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Voucher Rp 20.000',
            'description' => 'Desc',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ], $over));
    }

    private function makeClaim(User $warga, Voucher $voucher, string $token = 'KBK-AAA-BBB'): VoucherClaim
    {
        return VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => $token,
            'status' => 'claimed',
            'claimed_at' => now(),
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

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-XXX-YYY'])
            ->assertStatus(401);
    }

    public function test_warga_returns_403(): void
    {
        $warga = $this->makeWarga();

        $this->actingAs($warga, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-XXX-YYY'])
            ->assertStatus(403);
    }

    public function test_validation_requires_existing_code(): void
    {
        $mitra = $this->makeMitra(User::factory()->mitra()->create());

        $this->actingAs($mitra->user, 'sanctum')
            ->postJson('/api/vouchers/redeem', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($mitra->user, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-NOT-EXIST'])
            ->assertStatus(422);
    }

    public function test_success_redeem_updates_claim_disbursement_balance(): void
    {
        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);
        $claim = $this->makeClaim($warga, $voucher, 'KBK-OK-001');

        $response = $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-OK-001'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonPath('data.claim_id', $claim->id);
        $this->assertSame('completed', $response->json('data.disbursement_status'));

        $this->assertSame('used', $claim->refresh()->status);
        $this->assertNotNull($claim->refresh()->used_at);
        $this->assertSame('20000.00', $response->json('data.amount'));
        $this->assertSame('20000.00', $response->json('data.new_balance'));

        $this->assertDatabaseHas('disbursements', [
            'voucher_claim_id' => $claim->id,
            'mitra_profile_id' => $mitra->id,
            'status' => 'completed',
        ]);

        $this->assertNotNull($response->json('data.xendit_payout_id'));
        $this->assertStringStartsWith('KBK-CLAIM-', $response->json('data.reference_id'));
    }

    public function test_double_redeem_returns_409(): void
    {
        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);
        $this->makeClaim($warga, $voucher, 'KBK-DOUBLE-1');

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-DOUBLE-1'])
            ->assertOk();

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-DOUBLE-1'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_foreign_merchant_voucher_returns_403(): void
    {
        $warga = $this->makeWarga();
        $owner = $this->makeMitra(User::factory()->mitra()->create());
        $other = $this->makeMitra(User::factory()->mitra()->create());
        $voucher = $this->makeVoucher($owner);
        $this->makeClaim($warga, $voucher, 'KBK-FOREIGN-1');

        $this->actingAs($other->user, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-FOREIGN-1'])
            ->assertStatus(403);
    }

    public function test_expired_voucher_returns_422(): void
    {
        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra, ['expired_at' => now()->subDay()->toDateString()]);
        $this->makeClaim($warga, $voucher, 'KBK-EXP-001');

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-EXP-001'])
            ->assertStatus(422);
    }

    public function test_pending_mitra_cannot_redeem(): void
    {
        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser, ['status_verifikasi' => 'pending', 'is_active' => false]);
        $voucher = $this->makeVoucher($mitra);
        $this->makeClaim($warga, $voucher, 'KBK-PEND-001');

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-PEND-001'])
            ->assertStatus(403);
    }

    public function test_xendit_failure_rolls_back_claim(): void
    {
        Config::set('services.xendit.mock', false);
        Config::set('services.xendit.key', 'test-key');
        Http::fake(['*' => Http::response(['error' => 'bank invalid'], 500)]);

        $warga = $this->makeWarga();
        $mitraUser = User::factory()->mitra()->create();
        $mitra = $this->makeMitra($mitraUser);
        $voucher = $this->makeVoucher($mitra);
        $claim = $this->makeClaim($warga, $voucher, 'KBK-FAIL-001');

        $this->actingAs($mitraUser, 'sanctum')
            ->postJson('/api/vouchers/redeem', ['unique_code' => 'KBK-FAIL-001'])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertSame('claimed', $claim->refresh()->status);
        $this->assertDatabaseMissing('disbursements', ['voucher_claim_id' => $claim->id]);
        $this->assertSame('0.00', number_format((float) $mitra->refresh()->balance, 2, '.', ''));
    }
}
