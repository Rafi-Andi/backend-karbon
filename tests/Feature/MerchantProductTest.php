<?php

namespace Tests\Feature;

use App\Models\MitraProduct;
use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MerchantProductTest extends TestCase
{
    use RefreshDatabase;

    private function makeMitra(array $over = []): array
    {
        $user = User::factory()->mitra()->create();
        $mitra = MitraProfile::create(array_merge([
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

        return [$user, $mitra];
    }

    private function productPayload(array $over = []): array
    {
        return array_merge([
            'title' => 'Kopi Susu Gula Aren',
            'description' => 'Kopi susu segar kemasan 250ml.',
            'category' => 'kuliner',
            'rupiah_value' => 10000,
        ], $over);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/merchant/products')->assertStatus(401);
        $this->postJson('/api/merchant/products', $this->productPayload())->assertStatus(401);
    }

    public function test_warga_returns_403(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);

        $this->actingAs($warga, 'sanctum')
            ->getJson('/api/merchant/products')
            ->assertStatus(403);
    }

    public function test_mitra_can_crud_own_products(): void
    {
        [$user] = $this->makeMitra();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/merchant/products', $this->productPayload())
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        // Preview poin = ceil(10000 / 40) = 250.
        $this->assertSame(250, $created->json('data.points_preview'));
        $this->assertSame(40, $created->json('data.rupiah_per_point'));

        $id = $created->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/merchant/products')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/merchant/products/{$id}", ['rupiah_value' => 20000])
            ->assertOk()
            ->assertJsonPath('data.points_preview', 500);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/merchant/products/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('mitra_products', ['id' => $id]);
    }

    public function test_validation_rejects_bad_price(): void
    {
        [$user] = $this->makeMitra();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/merchant/products', $this->productPayload(['rupiah_value' => 500]))
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/merchant/products', $this->productPayload(['category' => 'bogus']))
            ->assertStatus(422);
    }

    public function test_cannot_touch_other_merchant_product(): void
    {
        [$userA] = $this->makeMitra();
        [$userB, $mitraB] = $this->makeMitra();

        $productB = MitraProduct::create([
            'mitra_profile_id' => $mitraB->id,
            'title' => 'Milik B',
            'description' => 'x',
            'category' => 'kuliner',
            'rupiah_value' => 15000,
            'is_active' => true,
        ]);

        $this->actingAs($userA, 'sanctum')
            ->patchJson("/api/merchant/products/{$productB->id}", ['title' => 'Curi'])
            ->assertStatus(404);

        $this->actingAs($userA, 'sanctum')
            ->deleteJson("/api/merchant/products/{$productB->id}")
            ->assertStatus(404);
    }

    public function test_create_with_photo_stores_file_and_public_url(): void
    {
        Storage::fake('public');
        [$user, $mitra] = $this->makeMitra();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/merchant/products', array_merge(
                $this->productPayload(),
                ['foto_produk' => UploadedFile::fake()->image('kopi.jpg')]
            ))
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $imageUrl = $response->json('data.image_url');
        $this->assertStringStartsWith('/storage/mitra-products/', (string) $imageUrl);

        $product = MitraProduct::first();
        Storage::disk('public')->assertExists($product->image_url);
    }

    public function test_create_with_bad_photo_mime_returns_422(): void
    {
        Storage::fake('public');
        [$user] = $this->makeMitra();

        $this->actingAs($user, 'sanctum')
            ->post('/api/merchant/products', array_merge(
                $this->productPayload(),
                ['foto_produk' => UploadedFile::fake()->create('foto.txt', 100, 'text/plain')]
            ))
            ->assertStatus(422);
    }

    public function test_upload_photo_replaces_and_deletes_old(): void
    {
        Storage::fake('public');
        [$user, $mitra] = $this->makeMitra();

        $product = MitraProduct::create([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Kopi',
            'description' => 'x',
            'category' => 'kuliner',
            'rupiah_value' => 10000,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->post("/api/merchant/products/{$product->id}/photo", [
                'foto_produk' => UploadedFile::fake()->image('satu.jpg'),
            ])
            ->assertOk();

        $first = $product->refresh()->image_url;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user, 'sanctum')
            ->post("/api/merchant/products/{$product->id}/photo", [
                'foto_produk' => UploadedFile::fake()->image('dua.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $second = $product->refresh()->image_url;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_upload_photo_other_merchant_returns_404(): void
    {
        Storage::fake('public');
        [$userA] = $this->makeMitra();
        [, $mitraB] = $this->makeMitra();

        $productB = MitraProduct::create([
            'mitra_profile_id' => $mitraB->id,
            'title' => 'Milik B',
            'description' => 'x',
            'category' => 'kuliner',
            'rupiah_value' => 15000,
            'is_active' => true,
        ]);

        $this->actingAs($userA, 'sanctum')
            ->post("/api/merchant/products/{$productB->id}/photo", [
                'foto_produk' => UploadedFile::fake()->image('curi.jpg'),
            ])
            ->assertStatus(404);
    }

    public function test_delete_with_funded_voucher_deactivates(): void
    {
        [$user, $mitra] = $this->makeMitra();

        $product = MitraProduct::create([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Sudah didanai',
            'description' => 'x',
            'category' => 'kuliner',
            'rupiah_value' => 20000,
            'is_active' => true,
        ]);

        Voucher::create([
            'mitra_profile_id' => $mitra->id,
            'mitra_product_id' => $product->id,
            'title' => $product->title,
            'description' => $product->description,
            'category' => 'kuliner',
            'points_cost' => 500,
            'rupiah_value' => 20000,
            'stock' => 5,
            'claimed_count' => 0,
            'expired_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/merchant/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse((bool) $product->refresh()->is_active);
        $this->assertDatabaseHas('mitra_products', ['id' => $product->id]);
    }
}
