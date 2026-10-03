<?php

namespace Tests\Feature;

use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeVoucher(string $title, string $category): Voucher
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = MitraProfile::create([
            'user_id' => $mitraUser->id,
            'nama_usaha' => 'Toko '.$mitraUser->id,
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Toko Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ]);

        return Voucher::create([
            'mitra_profile_id' => $mitra->id,
            'title' => $title,
            'description' => 'Desc '.$title,
            'category' => $category,
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);
    }

    public function test_index_without_category_returns_all(): void
    {
        $user = User::factory()->create(['role' => 'warga']);
        $this->makeVoucher('Voucher Kuliner A', 'kuliner');
        $this->makeVoucher('Voucher Fashion A', 'fashion');

        $response = $this->getJson('/api/vouchers', $this->authHeader($user));

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_filters_by_category(): void
    {
        $user = User::factory()->create(['role' => 'warga']);
        $this->makeVoucher('Voucher Kuliner A', 'kuliner');
        $this->makeVoucher('Voucher Fashion A', 'fashion');

        $response = $this->getJson('/api/vouchers?category=fashion', $this->authHeader($user));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('fashion', $data[0]['category']);
        $this->assertSame('Voucher Fashion A', $data[0]['title']);
    }

    public function test_index_rejects_invalid_category(): void
    {
        $user = User::factory()->create(['role' => 'warga']);

        $this->getJson('/api/vouchers?category=invalid', $this->authHeader($user))
            ->assertStatus(422);
    }

    public function test_voucher_without_category_defaults_to_kuliner(): void
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = MitraProfile::create([
            'user_id' => $mitraUser->id,
            'nama_usaha' => 'Toko Default',
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Toko Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ]);

        $voucher = Voucher::create([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Voucher Tanpa Kategori',
            'description' => 'Desc',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertSame('kuliner', $voucher->refresh()->category);
    }
}
