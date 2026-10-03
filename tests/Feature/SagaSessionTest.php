<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\Quiz;
use App\Models\User;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SagaSessionTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        WargaProfile::create([
            'user_id' => $user->id,
            'xp' => 0,
            'level' => 'Earth Warrior',
            'eco_points' => 0,
            'streak_days' => 0,
        ]);

        return $user;
    }

    private function makeQuizMission(array $overrides = []): Mission
    {
        return Mission::create(array_merge([
            'title' => 'Quiz Mission',
            'description' => 'Quiz description',
            'category' => 'quiz',
            'xp_reward' => 50,
            'points_reward' => 0,
            'icon' => 'quiz',
            'max_participants' => null,
            'is_active' => true,
        ], $overrides));
    }

    /** Buat 5 soal (A semua salah, B benar) untuk 1 misi. */
    private function makeBank(Mission $mission): void
    {
        foreach (range(1, 5) as $i) {
            Quiz::create([
                'mission_id' => $mission->id,
                'question' => "Question {$i}?",
                'options' => ['A' => 'Wrong', 'B' => 'Right', 'C' => 'Wrong', 'D' => 'Wrong'],
                'correct_answer' => 'B',
                'explanation' => "Explanation {$i}.",
                'order' => $i,
            ]);
        }
    }

    /** Ambil node playable + daftar soal sesinya. */
    private function playableSession(User $user): array
    {
        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $playable = collect($nodes)->firstWhere('is_playable_today', true);
        $this->assertNotNull($playable, 'Harus ada node playable.');

        $questions = $this->getJson(
            "/api/saga/nodes/{$playable['id']}/questions",
            $this->authHeader($user)
        )->json('data');

        return [$playable, $questions];
    }

    public function test_questions_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/saga/nodes/1/questions')->assertStatus(401);
    }

    public function test_questions_unknown_node_returns_404(): void
    {
        $user = $this->makeUser();

        $this->getJson('/api/saga/nodes/999/questions', $this->authHeader($user))
            ->assertStatus(404);
    }

    public function test_questions_locked_node_returns_403(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission(['title' => 'A']));
        $this->makeBank($this->makeQuizMission(['title' => 'B']));
        $locked = $this->makeQuizMission(['title' => 'Locked']);
        $this->makeBank($locked);

        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $lockedNode = collect($nodes)->firstWhere('is_playable_today', false);
        $this->assertNotNull($lockedNode);

        $this->getJson(
            "/api/saga/nodes/{$lockedNode['id']}/questions",
            $this->authHeader($user)
        )->assertStatus(403);
    }

    public function test_questions_returns_five_without_leak(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission());

        [$playable, $session] = $this->playableSession($user);

        $this->assertEquals(5, $session['total']);
        $this->assertCount(5, $session['questions']);
        $this->assertEquals($playable['id'], $session['mission_id']);
        $this->assertEquals(10, $session['xp_per_question']);

        $raw = json_encode($session);
        $this->assertStringNotContainsString('correct_answer', $raw);
        $this->assertStringNotContainsString('explanation', $raw);
    }

    public function test_questions_deterministic_within_day(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission());

        [$playable] = $this->playableSession($user);
        $first = $this->getJson(
            "/api/saga/nodes/{$playable['id']}/questions",
            $this->authHeader($user)
        )->json('data.questions');
        $second = $this->getJson(
            "/api/saga/nodes/{$playable['id']}/questions",
            $this->authHeader($user)
        )->json('data.questions');

        $this->assertEquals(array_column($first, 'id'), array_column($second, 'id'));
    }

    public function test_correct_answer_gives_split_xp(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission(['xp_reward' => 50]));

        [, $session] = $this->playableSession($user);
        $first = $session['questions'][0];

        $response = $this->postJson('/api/saga/answer', [
            'quiz_id' => $first['id'],
            'answer' => 'B',
        ], $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('data.is_correct', true)
            ->assertJsonPath('data.xp_earned', 10)
            ->assertJsonPath('data.node_completed', false)
            ->assertJsonPath('data.remaining', 4)
            ->assertJsonPath('data.session_correct', 1)
            ->assertJsonPath('data.session_xp', 10);

        $this->assertEquals(10, $user->fresh()->wargaProfile->xp);
    }

    public function test_wrong_answer_forfeits_and_rejects_repeat(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission());

        [, $session] = $this->playableSession($user);
        $first = $session['questions'][0];

        $response = $this->postJson('/api/saga/answer', [
            'quiz_id' => $first['id'],
            'answer' => 'A',
        ], $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('data.is_correct', false)
            ->assertJsonPath('data.xp_earned', 0)
            ->assertJsonPath('data.remaining', 4);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('correct_answer', $raw);

        // Ulangi soal yang sama → 409 (hangus).
        $this->postJson('/api/saga/answer', [
            'quiz_id' => $first['id'],
            'answer' => 'B',
        ], $this->authHeader($user))->assertStatus(409);

        $this->assertEquals(0, $user->fresh()->wargaProfile->xp);
    }

    public function test_answered_question_reveals_answer_via_questions(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission());

        [$playable, $session] = $this->playableSession($user);
        $first = $session['questions'][0];

        $this->postJson('/api/saga/answer', [
            'quiz_id' => $first['id'],
            'answer' => 'A',
        ], $this->authHeader($user))->assertOk();

        $again = $this->getJson(
            "/api/saga/nodes/{$playable['id']}/questions",
            $this->authHeader($user)
        )->json('data.questions');

        $answered = collect($again)->firstWhere('id', $first['id']);
        $this->assertTrue($answered['is_answered_today']);
        $this->assertFalse($answered['was_correct']);
        $this->assertEquals('B', $answered['correct_answer']);
        $this->assertNotEmpty($answered['explanation']);

        $pending = collect($again)->firstWhere('is_answered_today', false);
        $this->assertArrayNotHasKey('correct_answer', $pending);
    }

    public function test_complete_all_five_marks_node_completed(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission(['xp_reward' => 50]));

        [$playable, $session] = $this->playableSession($user);

        foreach ($session['questions'] as $i => $q) {
            // 3 benar, 2 salah → total 30 XP.
            $answer = $i < 3 ? 'B' : 'A';
            $response = $this->postJson('/api/saga/answer', [
                'quiz_id' => $q['id'],
                'answer' => $answer,
            ], $this->authHeader($user));
            $response->assertOk();
        }

        $last = $response->json('data');
        $this->assertTrue($last['node_completed']);
        $this->assertEquals(0, $last['remaining']);
        $this->assertEquals(3, $last['session_correct']);
        $this->assertEquals(30, $last['session_xp']);
        $this->assertEquals(30, $user->fresh()->wargaProfile->xp);

        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $node = collect($nodes)->firstWhere('id', $playable['id']);
        $this->assertTrue($node['is_completed_today']);
        $this->assertEquals(5, $node['answered_today']);
        $this->assertEquals(5, $node['total_questions']);
    }

    public function test_answer_quiz_from_locked_mission_returns_422(): void
    {
        $user = $this->makeUser();
        $this->makeBank($this->makeQuizMission(['title' => 'A']));
        $this->makeBank($this->makeQuizMission(['title' => 'B']));
        $locked = $this->makeQuizMission(['title' => 'Locked']);
        $this->makeBank($locked);

        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $lockedNode = collect($nodes)->firstWhere('is_playable_today', false);
        $lockedQuiz = Quiz::where('mission_id', $lockedNode['id'])->first();

        $this->postJson('/api/saga/answer', [
            'quiz_id' => $lockedQuiz->id,
            'answer' => 'B',
        ], $this->authHeader($user))->assertStatus(422);
    }

    public function test_sequential_unlock_two_nodes_answerable_same_day(): void
    {
        $user = $this->makeUser();
        $missionA = $this->makeQuizMission(['title' => 'A', 'xp_reward' => 50]);
        $this->makeBank($missionA);
        $missionB = $this->makeQuizMission(['title' => 'B', 'xp_reward' => 50]);
        $this->makeBank($missionB);
        $this->makeBank($this->makeQuizMission(['title' => 'C', 'xp_reward' => 50]));

        // Awal: strict 1-terbuka — hanya node A yang playable.
        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $playables = collect($nodes)->where('is_playable_today', true)->values();
        $this->assertCount(1, $playables);
        $this->assertEquals($missionA->id, $playables->first()['id']);

        // Selesaikan node A penuh (5 soal) → node B langsung terbuka hari itu
        // juga karena kuota 2/hari belum habis.
        $sessionA = $this->getJson(
            "/api/saga/nodes/{$missionA->id}/questions",
            $this->authHeader($user)
        )->assertOk()->json('data');
        $totalXp = 0;
        foreach ($sessionA['questions'] as $question) {
            $response = $this->postJson('/api/saga/answer', [
                'quiz_id' => $question['id'],
                'answer' => 'B',
            ], $this->authHeader($user));
            $response->assertOk()->assertJsonPath('data.is_correct', true);
            $totalXp += $response->json('data.xp_earned');
        }
        $this->assertEquals(50, $totalXp);

        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $playables = collect($nodes)->where('is_playable_today', true)->values();
        $this->assertEquals(
            [$missionA->id, $missionB->id],
            $playables->pluck('id')->sort()->values()->all()
        );

        // Node B (aktif, belum selesai) bisa dijawab hari yang sama.
        $nodeB = collect($nodes)->firstWhere('id', $missionB->id);
        $this->assertFalse($nodeB['is_completed_today']);
        $sessionB = $this->getJson(
            "/api/saga/nodes/{$missionB->id}/questions",
            $this->authHeader($user)
        )->assertOk()->json('data');
        $response = $this->postJson('/api/saga/answer', [
            'quiz_id' => $sessionB['questions'][0]['id'],
            'answer' => 'B',
        ], $this->authHeader($user));
        $response->assertOk()->assertJsonPath('data.is_correct', true);
        $this->assertEquals(60, $user->fresh()->wargaProfile->xp);
    }

    public function test_route_name_resolves(): void
    {
        $this->assertEquals('/api/saga/nodes/1/questions', route('saga.questions', ['id' => 1], false));
    }
}
