<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'role:mitra'])->get('/_test/mitra-only', fn () => response()->json([
            'success' => true,
        ]));

        Route::middleware(['auth:sanctum', 'role:admin'])->get('/_test/admin-only', fn () => response()->json([
            'success' => true,
        ]));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/_test/mitra-only')->assertStatus(401);
    }

    public function test_wrong_role_returns_403_with_envelope(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);

        $this->actingAs($warga, 'sanctum')
            ->getJson('/_test/mitra-only')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_correct_role_passes(): void
    {
        $mitra = User::factory()->create(['role' => 'mitra']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($mitra, 'sanctum')->getJson('/_test/mitra-only')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/_test/admin-only')->assertOk();
    }
}
