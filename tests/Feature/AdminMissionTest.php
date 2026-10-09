<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMissionTest extends TestCase
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

    private function mobilityPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Lari Pagi 5Km',
            'description' => 'Lari pagi minimal 5km!',
            'category' => 'mobility',
            'xp_reward' => 200,
            'points_reward' => 75,
            'icon' => 'directions_run',
            'activity_type' => 'running',
            'target_distance_km' => 5.0,
        ], $overrides);
    }

    private function wastePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Pilah Kertas',
            'description' => 'Pilah sampah kertas!',
            'category' => 'waste',
            'xp_reward' => 100,
            'points_reward' => 40,
            'icon' => 'recycling',
            'validation_prompt' => 'Foto harus menunjukkan sampah KERTAS terpilah.',
        ], $overrides);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/admin/missions')->assertStatus(401);
        $this->postJson('/api/admin/missions', $this->mobilityPayload())->assertStatus(401);
    }

    public function test_warga_and_mitra_return_403(): void
    {
        $warga = User::factory()->create(['role' => 'warga']);
        $mitra = User::factory()->create(['role' => 'mitra']);

        foreach ([$warga, $mitra] as $user) {
            $this->getJson('/api/admin/missions', $this->authHeader($user))->assertStatus(403);
            $this->postJson('/api/admin/missions', $this->mobilityPayload(), $this->authHeader($user))->assertStatus(403);
            $this->patchJson('/api/admin/missions/1', ['title' => 'X'], $this->authHeader($user))->assertStatus(403);
            $this->patchJson('/api/admin/missions/1/status', ['is_active' => false], $this->authHeader($user))->assertStatus(403);
        }
    }

    public function test_index_returns_all_including_inactive(): void
    {
        $admin = $this->makeAdmin();
        $active = Mission::create(array_merge($this->mobilityPayload(), ['is_active' => true]));
        $inactive = Mission::create(array_merge($this->wastePayload(), ['is_active' => false]));
        Mission::create([
            'title' => 'Quiz', 'description' => 'x', 'category' => 'quiz',
            'xp_reward' => 10, 'points_reward' => 5, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/admin/missions', $this->authHeader($admin));

        $response->assertOk()->assertJsonPath('success', true);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertContains($inactive->id, $ids);
        $this->assertCount(2, $ids);

        $item = collect($response->json('data'))->firstWhere('id', $active->id);
        $this->assertEquals('running', $item['activity_type']);
        $this->assertEquals(5.0, (float) $item['target_distance_km']);
    }

    public function test_store_mobility_success(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->postJson(
            '/api/admin/missions',
            $this->mobilityPayload(),
            $this->authHeader($admin)
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Lari Pagi 5Km')
            ->assertJsonPath('data.activity_type', 'running')
            ->assertJsonPath('data.category', 'mobility');

        $mission = Mission::find($response->json('data.id'));
        $this->assertEquals('running', $mission->activity_type);
        $this->assertEquals(5.0, (float) $mission->target_distance_km);
        $this->assertTrue((bool) $mission->is_active);
    }

    public function test_store_mobility_requires_activity_and_target(): void
    {
        $admin = $this->makeAdmin();
        $payload = $this->mobilityPayload();
        unset($payload['activity_type'], $payload['target_distance_km']);

        $this->postJson('/api/admin/missions', $payload, $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['activity_type', 'target_distance_km']);

        $this->assertEquals(0, Mission::count());
    }

    public function test_store_mobility_rejects_waste_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson(
            '/api/admin/missions',
            array_merge($this->mobilityPayload(), ['validation_prompt' => 'x']),
            $this->authHeader($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['validation_prompt']);
    }

    public function test_store_waste_success_and_requires_prompt(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->postJson(
            '/api/admin/missions',
            $this->wastePayload(),
            $this->authHeader($admin)
        );

        $response->assertStatus(201)->assertJsonPath('data.category', 'waste');

        $payload = $this->wastePayload();
        unset($payload['validation_prompt']);

        $this->postJson('/api/admin/missions', $payload, $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['validation_prompt']);
    }

    public function test_store_waste_rejects_mobility_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson(
            '/api/admin/missions',
            array_merge($this->wastePayload(), ['activity_type' => 'walking', 'target_distance_km' => 1]),
            $this->authHeader($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['activity_type', 'target_distance_km']);
    }

    public function test_store_rejects_quiz_category(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson(
            '/api/admin/missions',
            ['title' => 'Q', 'description' => 'x', 'category' => 'quiz'],
            $this->authHeader($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['category']);
    }

    public function test_update_partial_and_rejects_category_change(): void
    {
        $admin = $this->makeAdmin();
        $mission = Mission::create($this->mobilityPayload());

        $this->patchJson(
            "/api/admin/missions/{$mission->id}",
            ['title' => 'Lari Sore 5Km', 'xp_reward' => 250],
            $this->authHeader($admin)
        )->assertOk()->assertJsonPath('data.title', 'Lari Sore 5Km');

        $this->assertEquals(250, $mission->refresh()->xp_reward);

        $this->patchJson(
            "/api/admin/missions/{$mission->id}",
            ['category' => 'waste'],
            $this->authHeader($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['category']);
    }

    public function test_update_rejects_cross_category_fields(): void
    {
        $admin = $this->makeAdmin();
        $mobility = Mission::create($this->mobilityPayload());
        $waste = Mission::create($this->wastePayload());

        $this->patchJson(
            "/api/admin/missions/{$mobility->id}",
            ['validation_prompt' => 'x'],
            $this->authHeader($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['validation_prompt']);

        $this->patchJson(
            "/api/admin/missions/{$waste->id}",
            ['activity_type' => 'walking'],
            $this->authHeader($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['activity_type']);
    }

    public function test_update_quiz_or_missing_returns_404(): void
    {
        $admin = $this->makeAdmin();
        $quiz = Mission::create([
            'title' => 'Quiz', 'description' => 'x', 'category' => 'quiz',
            'xp_reward' => 10, 'points_reward' => 5, 'is_active' => true,
        ]);

        $this->patchJson("/api/admin/missions/{$quiz->id}", ['title' => 'Y'], $this->authHeader($admin))->assertStatus(404);
        $this->patchJson('/api/admin/missions/9999', ['title' => 'Y'], $this->authHeader($admin))->assertStatus(404);
    }

    public function test_status_toggle(): void
    {
        $admin = $this->makeAdmin();
        $mission = Mission::create($this->mobilityPayload());

        $this->patchJson(
            "/api/admin/missions/{$mission->id}/status",
            ['is_active' => false],
            $this->authHeader($admin)
        )->assertOk()->assertJsonPath('data.is_completed_today', false);

        $this->assertFalse((bool) $mission->refresh()->is_active);

        // Nonaktif hilang dari active list warga.
        $warga = User::factory()->create(['role' => 'warga']);
        $list = $this->getJson('/api/missions/active', $this->authHeader($warga));
        $this->assertNull(collect($list->json('data'))->firstWhere('id', $mission->id));

        $this->patchJson(
            "/api/admin/missions/{$mission->id}/status",
            ['is_active' => true],
            $this->authHeader($admin)
        )->assertOk();

        $this->assertTrue((bool) $mission->refresh()->is_active);
    }

    public function test_status_validation_and_404(): void
    {
        $admin = $this->makeAdmin();
        $mission = Mission::create($this->mobilityPayload());

        $this->patchJson(
            "/api/admin/missions/{$mission->id}/status",
            [],
            $this->authHeader($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['is_active']);

        $this->patchJson(
            '/api/admin/missions/9999/status',
            ['is_active' => false],
            $this->authHeader($admin)
        )->assertStatus(404);
    }

    public function test_route_names_resolve(): void
    {
        $this->assertNotNull(route('admin.missions.index'));
        $this->assertNotNull(route('admin.missions.store'));
        $this->assertNotNull(route('admin.missions.update', ['id' => 1]));
        $this->assertNotNull(route('admin.missions.status', ['id' => 1]));
    }
}
