<?php

namespace Tests\Feature;

use App\Models\MitraProduct;
use App\Models\MitraProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_admin_can_list_products_with_fundable_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $verified = $this->makeMitra();
        $pending = $this->makeMitra(['status_verifikasi' => 'pending', 'is_active' => false]);

        $p1 = MitraProduct::create([
            'mitra_profile_id' => $verified->id,
            'title' => 'Kopi',
            'description' => 'x',
            'category' => 'kuliner',
            'rupiah_value' => 10000,
            'is_active' => true,
        ]);
        MitraProduct::create([
            'mitra_profile_id' => $pending->id,
            'title' => 'Pending P',
            'description' => 'x',
            'category' => 'kuliner',
            'rupiah_value' => 10000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/mitra-products')
            ->assertOk()
            ->assertJsonPath('success', true);

        $items = collect($response->json('data'));
        $this->assertSame(2, $items->count());
        $this->assertTrue((bool) $items->firstWhere('id', $p1->id)['fundable']);
        $this->assertSame(250, $items->firstWhere('id', $p1->id)['points_preview']);
    }

    public function test_filter_by_mitra(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->makeMitra();
        $b = $this->makeMitra();

        MitraProduct::create(['mitra_profile_id' => $a->id, 'title' => 'A1', 'description' => 'x', 'category' => 'kuliner', 'rupiah_value' => 12000, 'is_active' => true]);
        MitraProduct::create(['mitra_profile_id' => $b->id, 'title' => 'B1', 'description' => 'x', 'category' => 'kuliner', 'rupiah_value' => 12000, 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/mitra-products?mitra_profile_id={$a->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A1', $response->json('data.0.title'));
    }

    public function test_non_admin_forbidden(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);

        $this->getJson('/api/admin/mitra-products')->assertStatus(401);
        $this->actingAs($warga, 'sanctum')->getJson('/api/admin/mitra-products')->assertStatus(403);
    }
}
