<?php

namespace Tests\Feature;

use App\Models\Disbursement;
use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantDisbursementHistoryTest extends TestCase
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

    private function makeDisbursement(User $mitra, array $attrs = []): Disbursement
    {
        $profile = $mitra->mitraProfile;

        $voucher = Voucher::create([
            'mitra_profile_id' => $profile->id,
            'title' => 'Voucher Test',
            'description' => 'Desc',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 1,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $warga = User::factory()->create([
            'role' => 'warga',
            'name' => 'Budi Warga',
            'rt' => '005',
            'rw' => '02',
        ]);

        $claim = VoucherClaim::create([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => 'KBK-'.strtoupper(substr(md5((string) mt_rand()), 0, 3)).'-'.strtoupper(substr(md5((string) mt_rand()), 0, 3)),
            'status' => 'used',
            'claimed_at' => now(),
            'used_at' => now(),
        ]);

        return Disbursement::create(array_merge([
            'mitra_profile_id' => $profile->id,
            'voucher_claim_id' => $claim->id,
            'xendit_disbursement_id' => 'po-test-1',
            'payout_id' => 'po-test-1',
            'reference_id' => 'KBK-CLAIM-'.$claim->id,
            'amount' => 20000,
            'bank_name' => 'Bank BCA',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'Warung Test',
            'status' => 'completed',
            'raw_status' => 'SUCCEEDED',
            'currency' => 'IDR',
            'destination_amount' => 20000,
            'destination_currency' => 'IDR',
            'response_log' => [],
        ], $attrs));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/merchant/disbursements')->assertStatus(401);
    }

    public function test_warga_returns_403(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);

        $this->actingAs($warga, 'sanctum')
            ->getJson('/api/merchant/disbursements')
            ->assertStatus(403);
    }

    public function test_mitra_gets_history_with_warga_and_voucher(): void
    {
        $mitra = $this->makeMitra();
        $this->makeDisbursement($mitra);

        $this->actingAs($mitra, 'sanctum')
            ->getJson('/api/merchant/disbursements')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.amount', '20000.00')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.warga_name', 'Budi Warga')
            ->assertJsonPath('data.0.warga_rt', '005')
            ->assertJsonPath('data.0.voucher_title', 'Voucher Test')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_history_only_shows_own_disbursements(): void
    {
        $mitra = $this->makeMitra();
        $other = $this->makeMitra(['nama_usaha' => 'Toko Lain']);
        $this->makeDisbursement($other);

        $this->actingAs($mitra, 'sanctum')
            ->getJson('/api/merchant/disbursements')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_filter_by_status(): void
    {
        $mitra = $this->makeMitra();
        $this->makeDisbursement($mitra, ['status' => 'completed']);
        $this->makeDisbursement($mitra, [
            'status' => 'failed',
            'raw_status' => 'FAILED',
            'xendit_disbursement_id' => 'po-test-2',
            'payout_id' => 'po-test-2',
        ]);

        $this->actingAs($mitra, 'sanctum')
            ->getJson('/api/merchant/disbursements?status=failed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_invalid_status_filter_returns_422(): void
    {
        $mitra = $this->makeMitra();

        $this->actingAs($mitra, 'sanctum')
            ->getJson('/api/merchant/disbursements?status=bogus')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
