<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\User;
use App\Models\UserMission;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardWilayahTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeWarga(array $wilayah): User
    {
        $user = User::factory()->create(array_merge(['role' => 'warga'], $wilayah));
        WargaProfile::create([
            'user_id' => $user->id,
            'level' => 'Earth Newbie',
            'xp' => 0,
            'eco_points' => 0,
            'streak_days' => 0,
        ]);

        return $user;
    }

    private function makeMission(int $xp = 100): Mission
    {
        return Mission::create([
            'title' => 'Misi Tes '.uniqid(),
            'description' => 'desc',
            'category' => 'waste',
            'xp_reward' => $xp,
            'points_reward' => $xp,
            'is_active' => true,
        ]);
    }

    private function giveVerifiedXp(User $user, Mission $mission): void
    {
        UserMission::create(['user_id' => $user->id, 'mission_id' => $mission->id, 'status' => 'verified']);
    }

    public function test_scope_rt_only_same_wilayah(): void
    {
        $wilayah = ['kota' => 'Surabaya', 'kecamatan' => 'Gubeng', 'kelurahan' => 'Mojo', 'rt' => '005', 'rw' => '02'];
        $me = $this->makeWarga($wilayah);
        $tetangga = $this->makeWarga($wilayah);
        // RT/RW sama tapi kota beda: tidak boleh muncul.
        $luarKota = $this->makeWarga(['kota' => 'Bandung', 'kecamatan' => 'Coblong', 'kelurahan' => 'Dago', 'rt' => '005', 'rw' => '02']);

        $mission = $this->makeMission(100);
        $this->giveVerifiedXp($me, $mission);
        $this->giveVerifiedXp($tetangga, $mission);
        $this->giveVerifiedXp($luarKota, $mission);

        $res = $this->getJson('/api/leaderboard?scope=rt&timeframe=weekly', $this->authHeader($me))
            ->assertOk()
            ->assertJsonPath('success', true);

        $names = collect($res->json('data.rankings'))->pluck('name')->all();
        $this->assertContains($me->name, $names);
        $this->assertContains($tetangga->name, $names);
        $this->assertNotContains($luarKota->name, $names);

        $wilayahRes = $res->json('data.wilayah');
        $this->assertSame('Surabaya', $wilayahRes['kota']);
        $this->assertSame('005', $wilayahRes['rt']);
    }

    public function test_scope_rw_same_kelurahan_not_other_kelurahan(): void
    {
        $base = ['kota' => 'Surabaya', 'kecamatan' => 'Gubeng', 'kelurahan' => 'Mojo', 'rw' => '02'];
        $me = $this->makeWarga(array_merge($base, ['rt' => '005']));
        $bedaRt = $this->makeWarga(array_merge($base, ['rt' => '006']));
        $bedaKelurahan = $this->makeWarga(['kota' => 'Surabaya', 'kecamatan' => 'Gubeng', 'kelurahan' => 'Pucang', 'rt' => '005', 'rw' => '02']);

        $mission = $this->makeMission(50);
        $this->giveVerifiedXp($me, $mission);
        $this->giveVerifiedXp($bedaRt, $mission);
        $this->giveVerifiedXp($bedaKelurahan, $mission);

        $res = $this->getJson('/api/leaderboard?scope=rw&timeframe=weekly', $this->authHeader($me))
            ->assertOk();

        $names = collect($res->json('data.rankings'))->pluck('name')->all();
        $this->assertContains($me->name, $names);
        $this->assertContains($bedaRt->name, $names);
        $this->assertNotContains($bedaKelurahan->name, $names);
    }
}
