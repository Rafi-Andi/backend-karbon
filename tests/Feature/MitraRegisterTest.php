<?php

namespace Tests\Feature;

use App\Models\MitraProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MitraRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Hendra',
            'email' => 'hendra.kopigembira@example.com',
            'phone' => '+6281234567890',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'city' => 'Surabaya',
            'district' => 'Sukolilo',
            'sub_district' => 'Sukolilo',
            'rt' => '003',
            'rw' => '02',
            'nama_usaha' => 'Kedai Kopi & Bakery Nusantara',
            'jenis_usaha' => 'Makanan & Minuman',
            'alamat_usaha' => 'Jl. Arif Rahman Hakim No 03, RT 03/RW 02',
            'usaha_kelurahan' => 'Sukolilo',
            'usaha_kecamatan' => 'Sukolilo',
            'usaha_kota' => 'Surabaya',
            'usaha_provinsi' => 'Jawa Timur',
            'usaha_kode_pos' => '60111',
            'nomor_ktp' => '3578111601900001',
            'nomor_nib' => '9120101234567',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Hendra',
        ], $over);
    }

    private function baseFiles(): array
    {
        return [
            'foto_ktp' => UploadedFile::fake()->image('KTP-Hendra.jpg'),
            'foto_nib' => UploadedFile::fake()->image('NIB_Usaha.jpg'),
            'foto_toko' => UploadedFile::fake()->image('toko1.jpg'),
            'foto_toko_2' => UploadedFile::fake()->image('toko2.jpg'),
            'foto_toko_3' => UploadedFile::fake()->image('toko3.jpg'),
        ];
    }

    public function test_success_registers_pending_with_format_valid(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(),
            $this->baseFiles()
        ))->assertStatus(201)->assertJsonPath('success', true);

        $response->assertJsonPath('data.user.role', 'mitra');
        $response->assertJsonPath('data.mitra.status_verifikasi', 'pending');
        $response->assertJsonPath('data.mitra.bank_validation_status', 'format_valid');
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull($response->json('data.mitra.foto_urls.foto_toko'));

        $mitra = MitraProfile::first();
        $this->assertSame('Kedai Kopi & Bakery Nusantara', $mitra->nama_usaha);
        $this->assertFalse((bool) $mitra->is_active);
        $this->assertSame('0.00', number_format((float) $mitra->balance, 2, '.', ''));

        foreach (['foto_ktp', 'foto_nib', 'foto_toko', 'foto_toko_2', 'foto_toko_3'] as $field) {
            $this->assertNotNull($mitra->{$field});
            Storage::disk('public')->assertExists($mitra->{$field});
        }
    }

    public function test_phone_with_dashes_is_normalized(): void
    {
        Storage::fake('public');

        $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(['phone' => '0812-3456-7890']),
            $this->baseFiles()
        ))->assertStatus(201);

        $this->assertDatabaseHas('users', ['phone' => '+6281234567890']);
    }

    public function test_missing_fields_returns_422(): void
    {
        $this->postJson('/api/auth/register-mitra', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors']);
    }

    public function test_duplicate_email_returns_422(): void
    {
        Storage::fake('public');
        User::factory()->create(['email' => 'hendra.kopigembira@example.com']);

        $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(),
            $this->baseFiles()
        ))->assertStatus(422)->assertJsonPath('errors.email.0', 'Email ini sudah terdaftar.');
    }

    public function test_invalid_ktp_returns_422(): void
    {
        Storage::fake('public');

        $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(['nomor_ktp' => '123']),
            $this->baseFiles()
        ))->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_invalid_file_mime_returns_422(): void
    {
        Storage::fake('public');

        $files = $this->baseFiles();
        $files['foto_ktp'] = UploadedFile::fake()->create('ktp.txt', 100, 'text/plain');

        $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(),
            $files
        ))->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_unknown_bank_still_accepted_as_manual_review(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(['nama_bank' => 'Bank Arta Kedaton', 'email' => 'arta@example.com', 'phone' => '+6281299999999', 'nomor_ktp' => '3578111601900002', 'nomor_nib' => '9120101234599']),
            $this->baseFiles()
        ))->assertStatus(201);

        $response->assertJsonPath('data.mitra.bank_validation_status', 'manual_review');
        $this->assertNotNull($response->json('data.mitra.bank_validation_note'));
    }

    public function test_wrong_account_length_is_manual_review(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(['nomor_rekening' => '12345', 'email' => 'pendek@example.com', 'phone' => '+6281288888888', 'nomor_ktp' => '3578111601900003', 'nomor_nib' => '9120101234588']),
            $this->baseFiles()
        ))->assertStatus(201);

        $response->assertJsonPath('data.mitra.bank_validation_status', 'manual_review');
    }

    public function test_optional_toko_photos_can_be_omitted(): void
    {
        Storage::fake('public');

        $files = $this->baseFiles();
        unset($files['foto_toko_2'], $files['foto_toko_3']);

        $response = $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(['email' => 'satu@example.com', 'phone' => '+6281277777777', 'nomor_ktp' => '3578111601900004', 'nomor_nib' => '9120101234577']),
            $files
        ))->assertStatus(201);

        $response->assertJsonPath('data.mitra.foto_urls.foto_toko_2', null);
        $response->assertJsonPath('data.mitra.foto_urls.foto_toko_3', null);
    }

    public function test_admin_show_returns_validation_screen_data(): void
    {
        Storage::fake('public');

        $claim = $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(),
            $this->baseFiles()
        ))->assertStatus(201);

        $mitraId = $claim->json('data.mitra.id');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/merchants/{$mitraId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.store.nama_usaha', 'Kedai Kopi & Bakery Nusantara')
            ->assertJsonPath('data.owner.name', 'Hendra')
            ->assertJsonPath('data.rekening.bank', 'Bank BCA')
            ->assertJsonPath('data.rekening.bank_validation_status', 'format_valid')
            ->assertJsonStructure(['data' => ['foto_toko' => ['foto_1', 'foto_2', 'foto_3'], 'dokumen' => ['foto_ktp', 'foto_nib'], 'lokasi_usaha' => ['kota', 'provinsi']]]);
    }

    public function test_admin_show_404_and_403(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warga = User::factory()->create(['role' => 'warga']);

        $this->getJson('/api/admin/merchants/1')->assertStatus(401);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/merchants/9999')->assertStatus(404);
        $this->actingAs($warga, 'sanctum')->getJson('/api/admin/merchants/1')->assertStatus(403);
    }

    public function test_admin_index_filters_and_summary(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/api/auth/register-mitra', array_merge(
            $this->basePayload(),
            $this->baseFiles()
        ))->assertStatus(201);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/merchants?status=pending')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, $response->json('summary.pending'));
        $this->assertNotEmpty($response->json('data.data'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/merchants?status=verified')
            ->assertOk()
            ->assertJsonPath('summary.pending', 1);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/merchants?status=bogus')
            ->assertStatus(422);
    }
}
