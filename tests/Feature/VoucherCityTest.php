<?php

namespace Tests\Feature;

use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherCityTest extends TestCase
{
    use RefreshDatabase;

    private function makeVoucher(string $title, string $kota, array $over = []): Voucher
    {
        $mitraUser = User::factory()->mitra()->create();
        $mitra = MitraProfile::create(array_merge([
            'user_id' => $mitraUser->id,
            'nama_usaha' => 'Toko '.$mitraUser->id,
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'usaha_kelurahan' => 'Kel A',
            'usaha_kecamatan' => 'Kec A',
            'usaha_kota' => $kota,
            'usaha_provinsi' => 'Jawa Timur',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Toko Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ]));

        return Voucher::create(array_merge([
            'mitra_profile_id' => $mitra->id,
            'title' => $title,
            'description' => 'Desc '.$title,
            'category' => 'kuliner',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ], $over));
    }

    public function test_index_includes_city_and_address(): void
    {
        $user = User::factory()->create(['role' => 'warga']);
        $this->makeVoucher('Voucher SBY', 'Surabaya');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/vouchers')->assertOk();

        $mitra = $response->json('data.0.mitra');
        $this->assertSame('Surabaya', $mitra['city']);
        $this->assertSame('Surabaya', $mitra['address']['kota']);
        $this->assertSame('Jl. Test No 1', $mitra['address']['alamat']);
    }

    public function test_index_filters_by_city(): void
    {
        $user = User::factory()->create(['role' => 'warga']);
        $this->makeVoucher('Voucher SBY', 'Surabaya');
        $this->makeVoucher('Voucher BDG', 'Bandung');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vouchers?city=Surabaya')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Surabaya', $data[0]['mitra']['city']);
    }

    public function test_index_filters_by_city_and_category(): void
    {
        $user = User::factory()->create(['role' => 'warga']);
        $this->makeVoucher('Voucher SBY Kuliner', 'Surabaya');
        $this->makeVoucher('Voucher SBY Fashion', 'Surabaya', ['category' => 'fashion', 'title' => 'Voucher SBY Fashion']);
        $this->makeVoucher('Voucher BDG Kuliner', 'Bandung');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vouchers?city=Surabaya&category=fashion')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Voucher SBY Fashion', $response->json('data.0.title'));
    }

    public function test_cities_returns_distinct_ordered(): void
    {
        $user = User::factory()->create(['role' => 'warga']);
        $this->makeVoucher('V1', 'Surabaya');
        $this->makeVoucher('V2', 'Bandung');
        $this->makeVoucher('V3', 'Surabaya', ['title' => 'V3']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vouchers/cities')
            ->assertOk()
            ->assertJsonPath('success', true);

        $cities = collect($response->json('data'))->pluck('city')->all();
        $this->assertSame(['Bandung', 'Surabaya'], $cities);
        $this->assertSame(2, collect($response->json('data'))->firstWhere('city', 'Surabaya')['voucher_count']);
    }

    public function test_cities_requires_auth(): void
    {
        $this->getJson('/api/vouchers/cities')->assertStatus(401);
    }

    public function test_my_vouchers_include_city(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);
        WargaProfile::create([
            'user_id' => $warga->id, 'level' => 'Earth Newbie',
            'xp' => 0, 'eco_points' => 1000, 'streak_days' => 0,
        ]);
        $voucher = $this->makeVoucher('Voucher SBY', 'Surabaya');

        $this->actingAs($warga, 'sanctum')
            ->postJson('/api/vouchers/claim', ['voucher_id' => $voucher->id])
            ->assertStatus(201);

        $response = $this->actingAs($warga, 'sanctum')
            ->getJson('/api/user/my-vouchers')
            ->assertOk();

        $claim = $response->json('data.active.0') ?? $response->json('data')[0] ?? null;
        // myVouchers returns grouped active/used/expired in VoucherController.
        $active = $response->json('data.active') ?? [];
        $this->assertNotEmpty($active);
        $this->assertSame('Surabaya', $active[0]['mitra']['city']);
    }

    public function test_legacy_mitra_without_usaha_kota_falls_back_to_owner(): void
    {
        $user = User::factory()->create(['role' => 'warga']);
        $mitraUser = User::factory()->mitra()->create([
            'kota' => 'Surabaya', 'kecamatan' => 'Gubeng', 'kelurahan' => 'Mojo',
        ]);
        $mitra = MitraProfile::create([
            'user_id' => $mitraUser->id,
            'nama_usaha' => 'Toko Lawas',
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Lawas No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Toko Lawas',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ]);
        Voucher::create([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Voucher Lawas',
            'description' => 'Desc',
            'category' => 'kuliner',
            'points_cost' => 100,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/vouchers')->assertOk();
        $this->assertSame('Surabaya', $response->json('data.0.mitra.city'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/vouchers?city=Surabaya')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $cities = $this->actingAs($user, 'sanctum')
            ->getJson('/api/vouchers/cities')
            ->assertOk()
            ->json('data');
        $this->assertContains('Surabaya', collect($cities)->pluck('city')->all());
    }

    public function test_dashboard_includes_kota(): void
    {
        $warga = User::factory()->create(['role' => 'warga', 'kota' => 'Surabaya', 'kecamatan' => 'Gubeng']);
        WargaProfile::create([
            'user_id' => $warga->id, 'level' => 'Earth Newbie',
            'xp' => 0, 'eco_points' => 0, 'streak_days' => 0,
        ]);

        $response = $this->actingAs($warga, 'sanctum')
            ->getJson('/api/user/dashboard')
            ->assertOk();

        $this->assertSame('Surabaya', $response->json('data.user.kota'));
        $this->assertSame('Gubeng', $response->json('data.user.kecamatan'));
    }
}
