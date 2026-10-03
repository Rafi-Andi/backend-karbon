<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MobilityLog;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserMission;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobilitySyncTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeWarga(array $profile = []): User
    {
        $user = User::factory()->create(['role' => 'warga']);
        WargaProfile::create(array_merge([
            'user_id' => $user->id,
            'level' => 'Earth Newbie',
            'xp' => 0,
            'eco_points' => 0,
            'streak_days' => 0,
        ], $profile));

        return $user;
    }

    private function makeMobilityMission(array $overrides = []): Mission
    {
        return Mission::create(array_merge([
            'title' => 'Pejuang Pedal 2Km',
            'description' => 'Catat aktivitas bersepeda atau berjalan kaki!',
            'category' => 'mobility',
            'xp_reward' => 150,
            'points_reward' => 50,
            'icon' => 'directions_bike',
            'max_participants' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function route2(): array
    {
        return [
            ['lat' => -7.2800, 'lng' => 112.7900],
            ['lat' => -7.2900, 'lng' => 112.8000],
        ];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'activity_type' => 'cycling',
            'distance_km' => 2,
            'duration_seconds' => 1200,
            'gps_coordinates_path' => $this->route2(),
        ], $overrides);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->makeMobilityMission();

        $this->postJson('/api/missions/mobility-sync', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_success_cycling_with_default_mission(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMobilityMission();

        $response = $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(),
            $this->authHeader($user)
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'user_mission_id', 'mobility_log_id', 'status',
                    'xp_earned', 'points_earned', 'distance_km',
                    'duration_minutes', 'co2_saved_grams',
                    'new_xp', 'new_level', 'new_eco_points', 'streak_days',
                ],
            ])
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.xp_earned', 150)
            ->assertJsonPath('data.points_earned', 50)
            ->assertJsonPath('data.duration_minutes', 20);

        $this->assertEquals(420.0, $response->json('data.co2_saved_grams'));

        $userMission = UserMission::find($response->json('data.user_mission_id'));
        $this->assertEquals($mission->id, $userMission->mission_id);
        $this->assertEquals('verified', $userMission->status);
        $this->assertEquals(100, (float) $userMission->confidence_score);

        $log = MobilityLog::find($response->json('data.mobility_log_id'));
        $this->assertEquals($userMission->id, $log->user_mission_id);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('cycling', $log->transport_mode);
        $this->assertEquals(2.0, (float) $log->distance_km);
        $this->assertEquals(20, $log->duration_minutes);
        $this->assertEquals(420.0, (float) $log->co2_saved_grams);
        $this->assertEquals($this->route2()[0], $log->start_point);
        $this->assertEquals($this->route2()[1], $log->end_point);
        $this->assertEquals($this->route2(), $log->route_coordinates);

        $profile = WargaProfile::where('user_id', $user->id)->first();
        $this->assertEquals(150, $profile->xp);
        $this->assertEquals(50, $profile->eco_points);
        $this->assertEquals(1, $profile->streak_days);
        $this->assertEquals(2.0, (float) $profile->total_distance_km);
        $this->assertEquals(0.42, (float) $profile->total_carbon_saved_kg);

        $tx = PointTransaction::where('reference_id', $userMission->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('credit', $tx->type);
        $this->assertEquals(50, $tx->amount);
        $this->assertEquals(50, $tx->balance_after);
    }

    public function test_success_walking_with_explicit_mission(): void
    {
        $user = $this->makeWarga();
        $default = $this->makeMobilityMission(['title' => 'Default']);
        $explicit = $this->makeMobilityMission([
            'title' => 'Jalan Kaki 3Km',
            'xp_reward' => 100,
            'points_reward' => 40,
        ]);

        $response = $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload([
                'mission_id' => $explicit->id,
                'activity_type' => 'walking',
                'distance_km' => 3,
                'duration_seconds' => 2700,
            ]),
            $this->authHeader($user)
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.xp_earned', 100)
            ->assertJsonPath('data.points_earned', 40)
            ->assertJsonPath('data.duration_minutes', 45);

        $this->assertEquals(
            $explicit->id,
            UserMission::find($response->json('data.user_mission_id'))->mission_id
        );
        $this->assertEquals(
            'walking',
            MobilityLog::find($response->json('data.mobility_log_id'))->transport_mode
        );
        $this->assertNotEquals($default->id, UserMission::find($response->json('data.user_mission_id'))->mission_id);
    }

    public function test_duration_seconds_rounded_up_to_minutes(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        // 90 detik -> 2 menit; kecepatan 2km / 1.5 mnt = 80 km/h? Tidak:
        // 2 km dalam 90 detik = 80 km/h -> DITOLAK. Pakai jarak kecil.
        $response = $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['distance_km' => 0.5, 'duration_seconds' => 90]),
            $this->authHeader($user)
        );

        $response->assertStatus(201)->assertJsonPath('data.duration_minutes', 2);
        $this->assertEquals(
            2,
            MobilityLog::find($response->json('data.mobility_log_id'))->duration_minutes
        );
    }

    public function test_boundary_speed_exactly_30_kmh_passes(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        // 10 km dalam 1200 detik = tepat 30 km/h -> lolos (batas: > 30).
        $response = $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['distance_km' => 10, 'duration_seconds' => 1200]),
            $this->authHeader($user)
        );

        $response->assertStatus(201)->assertJsonPath('data.status', 'verified');
    }

    public function test_unrealistic_speed_rejected(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        // 10 km dalam 600 detik = 60 km/h -> mustahil untuk sepeda/kaki.
        $response = $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['distance_km' => 10, 'duration_seconds' => 600]),
            $this->authHeader($user)
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertEquals(60.0, $response->json('data.avg_speed_kmh'));

        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertEquals('rejected', $record->status);
        $this->assertNotNull($record->rejection_reason);
        $this->assertEquals(0, MobilityLog::count());

        $profile = WargaProfile::where('user_id', $user->id)->first();
        $this->assertEquals(0, $profile->xp);
        $this->assertEquals(0, $profile->eco_points);
        $this->assertEquals(0, PointTransaction::count());
    }

    public function test_validation_missing_fields(): void
    {
        $user = $this->makeWarga();

        $this->postJson('/api/missions/mobility-sync', [], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'activity_type', 'distance_km', 'duration_seconds', 'gps_coordinates_path',
            ]);
    }

    public function test_validation_invalid_activity_type(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['activity_type' => 'running']),
            $this->authHeader($user)
        )->assertStatus(422)->assertJsonValidationErrors(['activity_type']);
    }

    public function test_validation_distance_bounds(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['distance_km' => 0]),
            $this->authHeader($user)
        )->assertStatus(422)->assertJsonValidationErrors(['distance_km']);

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['distance_km' => 501]),
            $this->authHeader($user)
        )->assertStatus(422)->assertJsonValidationErrors(['distance_km']);
    }

    public function test_validation_duration_minimum(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['duration_seconds' => 59]),
            $this->authHeader($user)
        )->assertStatus(422)->assertJsonValidationErrors(['duration_seconds']);
    }

    public function test_validation_single_gps_point_rejected(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['gps_coordinates_path' => [['lat' => -7.28, 'lng' => 112.79]]]),
            $this->authHeader($user)
        )->assertStatus(422)->assertJsonValidationErrors(['gps_coordinates_path']);
    }

    public function test_validation_gps_coordinate_ranges(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['gps_coordinates_path' => [
                ['lat' => -91, 'lng' => 112.79],
                ['lat' => -7.29, 'lng' => 112.80],
            ]]),
            $this->authHeader($user)
        )->assertStatus(422);

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(['gps_coordinates_path' => [
                ['lat' => -7.28],
                ['lat' => -7.29, 'lng' => 112.80],
            ]]),
            $this->authHeader($user)
        )->assertStatus(422);
    }

    public function test_validation_wrong_mission_category_rejected(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission();
        $waste = Mission::create([
            'title' => 'Waste', 'description' => 'x', 'category' => 'waste',
            'xp_reward' => 10, 'points_reward' => 5, 'is_active' => true,
        ]);
        $quiz = Mission::create([
            'title' => 'Quiz', 'description' => 'x', 'category' => 'quiz',
            'xp_reward' => 10, 'points_reward' => 5, 'is_active' => true,
        ]);
        $inactive = $this->makeMobilityMission(['title' => 'Inactive', 'is_active' => false]);

        foreach ([$waste->id, $quiz->id, $inactive->id, 9999] as $badId) {
            $this->postJson(
                '/api/missions/mobility-sync',
                $this->validPayload(['mission_id' => $badId]),
                $this->authHeader($user)
            )->assertStatus(422)->assertJsonValidationErrors(['mission_id']);
        }

        $this->assertEquals(0, UserMission::count());
    }

    public function test_no_active_mission_without_id_returns_422(): void
    {
        $user = $this->makeWarga();
        $this->makeMobilityMission(['is_active' => false]);

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(),
            $this->authHeader($user)
        )->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_stats_accumulate_across_syncs(): void
    {
        $user = $this->makeWarga();
        $first = $this->makeMobilityMission();
        $second = $this->makeMobilityMission(['title' => 'Jalan Kaki 3Km']);

        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(['distance_km' => 2, 'duration_seconds' => 1200]), ['mission_id' => $first->id]),
            $this->authHeader($user)
        )->assertStatus(201);

        // Misi berbeda hari yang sama tetap dapat reward (kunci per misi).
        // 3 km dalam 1800 detik = 6 km/h -> valid.
        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(['distance_km' => 3, 'duration_seconds' => 1800]), ['mission_id' => $second->id]),
            $this->authHeader($user)
        )->assertStatus(201);

        $profile = WargaProfile::where('user_id', $user->id)->first();
        $this->assertEquals(300, $profile->xp);
        $this->assertEquals(100, $profile->eco_points);
        $this->assertEquals(5.0, (float) $profile->total_distance_km);
        $this->assertEquals(1.05, (float) $profile->total_carbon_saved_kg);
        $this->assertEquals(2, MobilityLog::where('user_id', $user->id)->count());
        $this->assertEquals(2, PointTransaction::where('user_id', $user->id)->count());
    }

    public function test_below_target_distance_returns_422(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMobilityMission(['target_distance_km' => 2.0]);

        // 1 km dalam 600 detik = 6 km/h (kecepatan valid, jarak kurang).
        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge(
                $this->validPayload(['distance_km' => 1, 'duration_seconds' => 600]),
                ['mission_id' => $mission->id]
            ),
            $this->authHeader($user)
        )->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.required_distance_km', 2);

        $this->assertEquals(0, UserMission::where('user_id', $user->id)->count());
        $this->assertEquals(0, WargaProfile::where('user_id', $user->id)->first()->eco_points);
    }

    public function test_at_target_distance_passes(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMobilityMission(['target_distance_km' => 2.0]);

        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(), ['mission_id' => $mission->id]),
            $this->authHeader($user)
        )->assertStatus(201);
    }

    public function test_active_list_includes_target_distance(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMobilityMission(['target_distance_km' => 2.0]);

        $list = $this->getJson('/api/missions/active', $this->authHeader($user));
        $list->assertOk();
        $this->assertEquals(
            2.0,
            (float) collect($list->json('data'))->firstWhere('id', $mission->id)['target_distance_km']
        );
    }

    public function test_same_mission_twice_same_day_returns_409(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(), ['mission_id' => $mission->id]),
            $this->authHeader($user)
        )->assertStatus(201);

        $pointsAfterFirst = WargaProfile::where('user_id', $user->id)->first()->eco_points;

        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(), ['mission_id' => $mission->id]),
            $this->authHeader($user)
        )->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.already_completed_today', true);

        // Poin tidak bertambah dari sync kedua.
        $this->assertEquals(
            $pointsAfterFirst,
            WargaProfile::where('user_id', $user->id)->first()->eco_points
        );
        $this->assertEquals(1, MobilityLog::where('user_id', $user->id)->count());
    }

    public function test_same_mission_next_day_allowed(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(), ['mission_id' => $mission->id]),
            $this->authHeader($user)
        )->assertStatus(201);

        $this->travel(1)->day();

        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(), ['mission_id' => $mission->id]),
            $this->authHeader($user)
        )->assertStatus(201);
    }

    public function test_active_list_marks_completed_today(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMobilityMission();

        $list = $this->getJson('/api/missions/active', $this->authHeader($user));
        $list->assertOk();
        $this->assertFalse((bool) collect($list->json('data'))->firstWhere('id', $mission->id)['is_completed_today']);

        $this->postJson(
            '/api/missions/mobility-sync',
            array_merge($this->validPayload(), ['mission_id' => $mission->id]),
            $this->authHeader($user)
        )->assertStatus(201);

        $list = $this->getJson('/api/missions/active', $this->authHeader($user));
        $list->assertOk();
        $this->assertTrue((bool) collect($list->json('data'))->firstWhere('id', $mission->id)['is_completed_today']);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->makeMobilityMission();

        $this->postJson(
            '/api/missions/mobility-sync',
            $this->validPayload(),
            ['Authorization' => 'Bearer invalid-token']
        )->assertStatus(401);
    }
}
