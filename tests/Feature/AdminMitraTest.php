<?php

namespace Tests\Feature;

use App\Models\MitraProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMitraTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeMitra(string $status = 'pending'): MitraProfile
    {
        $user = User::factory()->mitra()->create();

        return MitraProfile::create([
            'user_id' => $user->id,
            'nama_usaha' => 'Warung '.$user->id,
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Warung Test',
            'status_verifikasi' => $status,
            'is_active' => false,
            'balance' => 0,
        ]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/admin/merchants/pending')->assertStatus(401);
        $this->postJson('/api/admin/merchants/1/verify', ['action' => 'approve'])->assertStatus(401);
    }

    public function test_warga_and_mitra_return_403(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);
        $mitraUser = User::factory()->mitra()->create();
        $this->makeMitra('verified');

        $this->actingAs($warga, 'sanctum')
            ->getJson('/api/admin/merchants/pending')
            ->assertStatus(403);

        $this->actingAs($mitraUser, 'sanctum')
            ->getJson('/api/admin/merchants/pending')
            ->assertStatus(403);

        $this->actingAs($warga, 'sanctum')
            ->postJson('/api/admin/merchants/1/verify', ['action' => 'approve'])
            ->assertStatus(403);
    }

    public function test_pending_lists_only_pending(): void
    {
        $admin = $this->makeAdmin();
        $pending = $this->makeMitra('pending');
        $this->makeMitra('verified');
        $this->makeMitra('rejected');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/merchants/pending')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertCount(1, $ids);
        $this->assertNotNull($response->json('data.data.0.user'));
    }

    public function test_approve_activates_merchant(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitra('pending');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/merchants/{$mitra->id}/verify", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.is_active', true);

        $mitra->refresh();
        $this->assertSame('verified', $mitra->status_verifikasi);
        $this->assertTrue((bool) $mitra->is_active);
    }

    public function test_reject_deactivates_with_note(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitra('pending');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/merchants/{$mitra->id}/verify", [
                'action' => 'reject',
                'reason' => 'Dokumen KTP tidak jelas.',
            ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected')
            ->assertJsonPath('data.is_active', false);

        $this->assertSame('Dokumen KTP tidak jelas.', $mitra->refresh()->verification_note);
    }

    public function test_reject_without_reason_returns_422(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitra('pending');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/merchants/{$mitra->id}/verify", ['action' => 'reject'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_reverify_returns_409(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitra('verified');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/merchants/{$mitra->id}/verify", ['action' => 'approve'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_verify_nonexistent_returns_404(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/merchants/9999/verify', ['action' => 'approve'])
            ->assertStatus(404);
    }

    public function test_invalid_action_returns_422(): void
    {
        $admin = $this->makeAdmin();
        $mitra = $this->makeMitra('pending');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/merchants/{$mitra->id}/verify", ['action' => 'maybe'])
            ->assertStatus(422);
    }
}
