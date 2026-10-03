<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionActiveTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeMission(array $overrides = []): Mission
    {
        return Mission::create(array_merge([
            'title' => 'Test Mission',
            'description' => 'Test description',
            'category' => 'mobility',
            'xp_reward' => 100,
            'points_reward' => 50,
            'icon' => 'directions_bike',
            'max_participants' => null,
            'is_active' => true,
        ], $overrides));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/missions/active')->assertStatus(401);
    }

    public function test_authenticated_returns_envelope_with_data_array(): void
    {
        $user = User::factory()->create();
        $this->makeMission();

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => ['id', 'title', 'description', 'category', 'xp_reward', 'points_reward', 'icon'],
                ],
            ])
            ->assertJsonPath('success', true);
        $this->assertIsArray($response->json('data'));
    }

    public function test_returns_only_mobility_and_waste(): void
    {
        $user = User::factory()->create();
        $mobility = $this->makeMission(['title' => 'Mobility A', 'category' => 'mobility']);
        $waste = $this->makeMission(['title' => 'Waste A', 'category' => 'waste']);
        $this->makeMission(['title' => 'Quiz A', 'category' => 'quiz']);

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mobility->id, $ids);
        $this->assertContains($waste->id, $ids);
        $this->assertCount(2, $response->json('data'));

        $categories = collect($response->json('data'))->pluck('category')->unique()->all();
        $this->assertEmpty(array_diff($categories, ['mobility', 'waste']));
        $this->assertNotContains('quiz', $categories);
    }

    public function test_excludes_inactive_missions(): void
    {
        $user = User::factory()->create();
        $active = $this->makeMission(['title' => 'Active', 'is_active' => true]);
        $this->makeMission(['title' => 'Inactive mobility', 'category' => 'mobility', 'is_active' => false]);
        $this->makeMission(['title' => 'Inactive waste', 'category' => 'waste', 'is_active' => false]);
        $this->makeMission(['title' => 'Inactive quiz', 'category' => 'quiz', 'is_active' => false]);

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$active->id], $ids);
    }

    public function test_returns_empty_array_when_no_active_missions(): void
    {
        $user = User::factory()->create();
        $this->makeMission(['category' => 'quiz']);
        $this->makeMission(['category' => 'mobility', 'is_active' => false]);

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_returns_empty_array_when_table_is_empty(): void
    {
        $user = User::factory()->create();

        $this->assertEquals(0, Mission::count());

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_results_ordered_by_id_ascending(): void
    {
        $user = User::factory()->create();
        $first = $this->makeMission(['title' => 'First', 'category' => 'waste']);
        $second = $this->makeMission(['title' => 'Second', 'category' => 'mobility']);
        $third = $this->makeMission(['title' => 'Third', 'category' => 'waste']);

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$first->id, $second->id, $third->id], $ids);
    }

    public function test_quiz_only_data_returns_empty(): void
    {
        $user = User::factory()->create();
        $this->makeMission(['title' => 'Quiz 1', 'category' => 'quiz']);
        $this->makeMission(['title' => 'Quiz 2', 'category' => 'quiz']);

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_resource_fields_match_mission_attributes(): void
    {
        $user = User::factory()->create();
        $mission = $this->makeMission([
            'title' => 'Pilah Sampah Elektronik',
            'description' => 'Pilah e-waste dengan benar',
            'category' => 'waste',
            'xp_reward' => 250,
            'points_reward' => 80,
            'icon' => 'memory',
        ]);

        $response = $this->getJson('/api/missions/active', $this->authHeader($user));

        $response->assertOk();
        $item = collect($response->json('data'))->firstWhere('id', $mission->id);
        $this->assertNotNull($item);
        $this->assertEquals('Pilah Sampah Elektronik', $item['title']);
        $this->assertEquals('Pilah e-waste dengan benar', $item['description']);
        $this->assertEquals('waste', $item['category']);
        $this->assertEquals(250, $item['xp_reward']);
        $this->assertEquals(80, $item['points_reward']);
        $this->assertEquals('memory', $item['icon']);
    }

    public function test_different_users_see_same_active_list(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->makeMission(['title' => 'Shared mobility']);
        $this->makeMission(['title' => 'Shared waste', 'category' => 'waste']);

        $resA = $this->getJson('/api/missions/active', $this->authHeader($userA));
        $resB = $this->getJson('/api/missions/active', $this->authHeader($userB));

        $resA->assertOk();
        $resB->assertOk();
        $this->assertEquals($resA->json('data'), $resB->json('data'));
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->makeMission();

        $this->getJson('/api/missions/active', ['Authorization' => 'Bearer invalid-token'])
            ->assertStatus(401);
    }

    public function test_route_name_resolves(): void
    {
        $this->assertEquals('/api/missions/active', route('missions.active', [], false));
    }
}
