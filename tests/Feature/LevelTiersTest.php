<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LevelTiersTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeWarga(int $xp, string $level = 'Earth Newbie'): User
    {
        $user = User::factory()->create(['role' => 'warga']);
        WargaProfile::create([
            'user_id' => $user->id,
            'level' => $level,
            'xp' => $xp,
            'eco_points' => 0,
            'streak_days' => 0,
        ]);

        return $user;
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/user/levels')->assertStatus(401);
    }

    public function test_newbie_unlock_satu(): void
    {
        $user = $this->makeWarga(100);

        $response = $this->getJson('/api/user/levels', $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.xp', 100)
            ->assertJsonPath('data.current_tier', 'newbie');

        $tiers = $response->json('data.tiers');
        $this->assertCount(3, $tiers);
        $this->assertTrue($tiers[0]['is_unlocked']);
        $this->assertFalse($tiers[1]['is_unlocked']);
        $this->assertFalse($tiers[2]['is_unlocked']);
    }

    public function test_keeper_unlock_dua_warrior_lock(): void
    {
        // Label basi (dulu seeder menyimpan Warrior 7 untuk 1080 XP)
        // untuk buktikan unlock dihitung dari XP, bukan label tersimpan.
        $user = $this->makeWarga(1080, 'Earth Warrior 7');

        $response = $this->getJson('/api/user/levels', $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('data.current_tier', 'keeper');

        $tiers = $response->json('data.tiers');
        $this->assertTrue($tiers[0]['is_unlocked']);
        $this->assertTrue($tiers[1]['is_unlocked']);
        $this->assertTrue($tiers[1]['is_current']);
        $this->assertFalse($tiers[2]['is_unlocked']);
    }

    public function test_warrior_semua_terbuka(): void
    {
        $user = $this->makeWarga(2500, 'Earth Warrior 8');

        $response = $this->getJson('/api/user/levels', $this->authHeader($user));

        $response->assertOk()->assertJsonPath('data.current_tier', 'warrior');

        foreach ($response->json('data.tiers') as $tier) {
            $this->assertTrue($tier['is_unlocked']);
        }
    }

    public function test_profile_tidak_ada_404(): void
    {
        $user = User::factory()->create(['role' => 'warga']);

        $this->getJson('/api/user/levels', $this->authHeader($user))->assertStatus(404);
    }

    public function test_route_name_resolves(): void
    {
        $this->assertEquals('/api/user/levels', route('user.levels', [], false));
    }
}
