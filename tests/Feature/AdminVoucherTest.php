<?php

namespace Tests\Feature;

use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kelola Reward admin: daftar semua reward, detail, daftar penukar,
 * toggle aktif/nonaktif, dan ubah tenggat (stok dikunci alokasi dana
 * campaign — payload stok ditolak).
 */
class AdminVoucherTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeMitraProfile(): MitraProfile
    {
        $user = User::factory()->mitra()->create();

        return MitraProfile::create([
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
        ]);
    }

    private function makeVoucher(MitraProfile $mitra, array $over = []): Voucher
    {
        return Voucher::create(array_merge([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Voucher Rp 20.000',
            'description' => 'Desc',
            'category' => 'kuliner',
            'points_cost' => 500,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ], $over));
    }

    private function makeWarga(array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => 'warga'], $over));
    }

    private function makeClaim(User $warga, Voucher $voucher, string $token, array $over = []): VoucherClaim
    {
        return VoucherClaim::create(array_merge([
            'user_id' => $warga->id,
            'voucher_id' => $voucher->id,
            'qr_token' => $token,
            'status' => 'claimed',
            'claimed_at' => now(),
        ], $over));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/admin/vouchers')->assertStatus(401);
        $this->patchJson('/api/admin/vouchers/1/status', ['is_active' => false])->assertStatus(401);
        $this->patchJson('/api/admin/vouchers/1', ['expired_at' => '2030-01-01'])->assertStatus(401);
        $this->getJson('/api/admin/vouchers/1/claims')->assertStatus(401);
    }

    public function test_warga_and_mitra_return_403(): void
    {
        $warga = $this->makeWarga();
        $mitra = User::factory()->mitra()->create();

        foreach ([$warga, $mitra] as $user) {
            $this->getJson('/api/admin/vouchers', $this->authHeader($user))->assertStatus(403);
            $this->getJson('/api/admin/vouchers/1', $this->authHeader($user))->assertStatus(403);
            $this->getJson('/api/admin/vouchers/1/claims', $this->authHeader($user))->assertStatus(403);
            $this->patchJson('/api/admin/vouchers/1', ['expired_at' => '2030-01-01'], $this->authHeader($user))->assertStatus(403);
            $this->patchJson('/api/admin/vouchers/1/status', ['is_active' => false], $this->authHeader($user))->assertStatus(403);
        }
    }

    public function test_index_returns_all_vouchers_with_counts(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitraProfile();
        $active = $this->makeVoucher($mitra);
        $inactive = $this->makeVoucher($mitra, [
            'title' => 'Voucher Lama',
            'is_active' => false,
            'expired_at' => now()->subDay()->toDateString(),
        ]);

        $wargaA = $this->makeWarga();
        $wargaB = $this->makeWarga();
        $this->makeClaim($wargaA, $active, 'KBK-ADM-001');
        $this->makeClaim($wargaB, $active, 'KBK-ADM-002', [
            'status' => 'used',
            'used_at' => now(),
        ]);
        $this->makeClaim($wargaA, $inactive, 'KBK-ADM-003');

        $response = $this->getJson('/api/admin/vouchers', $this->authHeader($admin));

        $response->assertOk()->assertJsonPath('success', true);

        $items = collect($response->json('data'));
        $this->assertCount(2, $items);

        $activeItem = $items->firstWhere('id', $active->id);
        $this->assertSame(2, $activeItem['claims_count']);
        $this->assertSame(1, $activeItem['used_claims_count']);
        $this->assertTrue($activeItem['is_active']);
        $this->assertSame('Warung Test', $activeItem['mitra']['store_name']);
        $this->assertSame($active->expired_at->format('Y-m-d'), $activeItem['expired_at']);

        // Reward nonaktif tetap tampil di layar kelola.
        $inactiveItem = $items->firstWhere('id', $inactive->id);
        $this->assertFalse($inactiveItem['is_active']);
        $this->assertSame(1, $inactiveItem['claims_count']);
        $this->assertSame(0, $inactiveItem['used_claims_count']);
    }

    public function test_show_detail_and_404(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitraProfile();
        $voucher = $this->makeVoucher($mitra);

        $this->getJson("/api/admin/vouchers/{$voucher->id}", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.title', 'Voucher Rp 20.000')
            ->assertJsonPath('data.claims_count', 0);

        $this->getJson('/api/admin/vouchers/9999', $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->getJson('/api/admin/vouchers/9999/claims', $this->authHeader($admin))
            ->assertStatus(404);
    }

    public function test_claims_lists_claimants_with_user_data(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitraProfile();
        $voucher = $this->makeVoucher($mitra);

        $warga = $this->makeWarga(['phone' => '08123456789', 'rt' => '03', 'rw' => '05']);
        $this->makeClaim($warga, $voucher, 'KBK-CLT-001', ['claimed_at' => now()->subDay()]);
        $this->makeClaim($this->makeWarga(), $voucher, 'KBK-CLT-002', ['claimed_at' => now()]);

        $response = $this->getJson("/api/admin/vouchers/{$voucher->id}/claims", $this->authHeader($admin));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1);

        // Terbaru dulu.
        $this->assertSame('KBK-CLT-002', $response->json('data.0.qr_token'));

        $old = collect($response->json('data'))->firstWhere('qr_token', 'KBK-CLT-001');
        $this->assertSame($warga->name, $old['user']['name']);
        $this->assertSame('08123456789', $old['user']['phone']);
        $this->assertSame('03', $old['user']['rt']);
        $this->assertSame('05', $old['user']['rw']);
        $this->assertSame('claimed', $old['status']);
    }

    public function test_update_expired_at_only(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitraProfile();
        $voucher = $this->makeVoucher($mitra);

        // Tenggat tersimpan.
        $newDate = now()->addWeeks(2)->toDateString();
        $this->patchJson("/api/admin/vouchers/{$voucher->id}", ['expired_at' => $newDate], $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.expired_at', $newDate);
        $this->assertSame($newDate, $voucher->refresh()->expired_at->toDateString());

        // Stok dikirim bareng tenggat → ditolak (alokasi dana campaign
        // hanya cocok dengan stok saat pendanaan), tidak ada yang berubah.
        $this->patchJson("/api/admin/vouchers/{$voucher->id}", [
            'expired_at' => now()->addMonths(2)->toDateString(),
            'stock' => 99,
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stock']);

        $voucher->refresh();
        $this->assertSame(5, $voucher->stock);
        $this->assertSame($newDate, $voucher->expired_at->toDateString());
    }

    public function test_update_validation_and_404(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitraProfile();
        $voucher = $this->makeVoucher($mitra);

        // Stok sudah tidak bisa diubah manual.
        $this->patchJson("/api/admin/vouchers/{$voucher->id}", ['stock' => 8], $this->authHeader($admin))
            ->assertStatus(422)->assertJsonValidationErrors(['stock']);
        $this->assertSame(5, $voucher->refresh()->stock);

        $this->patchJson("/api/admin/vouchers/{$voucher->id}", ['expired_at' => 'bukan-tanggal'], $this->authHeader($admin))
            ->assertStatus(422)->assertJsonValidationErrors(['expired_at']);

        // Payload kosong: tidak boleh no-op diam-diam.
        $this->patchJson("/api/admin/vouchers/{$voucher->id}", [], $this->authHeader($admin))
            ->assertStatus(422)->assertJsonValidationErrors(['expired_at']);

        $this->patchJson('/api/admin/vouchers/9999', ['expired_at' => '2030-01-01'], $this->authHeader($admin))
            ->assertStatus(404);
    }

    public function test_status_toggle_hides_from_marketplace(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitraProfile();
        $voucher = $this->makeVoucher($mitra);
        $warga = $this->makeWarga();

        // Nonaktif.
        $this->patchJson(
            "/api/admin/vouchers/{$voucher->id}/status",
            ['is_active' => false],
            $this->authHeader($admin)
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) $voucher->refresh()->is_active);

        // Hilang dari marketplace warga.
        $list = $this->getJson('/api/vouchers', $this->authHeader($warga));
        $this->assertNull(collect($list->json('data'))->firstWhere('id', $voucher->id));

        // Aktifkan lagi.
        $this->patchJson(
            "/api/admin/vouchers/{$voucher->id}/status",
            ['is_active' => true],
            $this->authHeader($admin)
        )->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertTrue((bool) $voucher->refresh()->is_active);
    }

    public function test_status_validation_and_404(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitraProfile();
        $voucher = $this->makeVoucher($mitra);

        $this->patchJson("/api/admin/vouchers/{$voucher->id}/status", [], $this->authHeader($admin))
            ->assertStatus(422)->assertJsonValidationErrors(['is_active']);

        $this->patchJson('/api/admin/vouchers/9999/status', ['is_active' => false], $this->authHeader($admin))
            ->assertStatus(404);
    }

    public function test_route_names_resolve(): void
    {
        $this->assertNotNull(route('admin.vouchers.index'));
        $this->assertNotNull(route('admin.vouchers.show', ['id' => 1]));
        $this->assertNotNull(route('admin.vouchers.update', ['id' => 1]));
        $this->assertNotNull(route('admin.vouchers.status', ['id' => 1]));
        $this->assertNotNull(route('admin.vouchers.claims', ['id' => 1]));
    }
}
