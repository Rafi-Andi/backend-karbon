<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\Quiz;
use App\Models\User;
use App\Models\UserMission;
use App\Models\WargaProfile;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SagaNodesTest extends TestCase
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

    private function makeQuiz(Mission $mission, array $overrides = []): Quiz
    {
        return Quiz::create(array_merge([
            'mission_id' => $mission->id,
            'question' => 'Test question?',
            'options' => ['A' => 'Opt A', 'B' => 'Opt B', 'C' => 'Opt C', 'D' => 'Opt D'],
            'correct_answer' => 'B',
            'explanation' => 'Because B.',
            'order' => 1,
        ], $overrides));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/saga/nodes')->assertStatus(401);
    }

    public function test_returns_envelope_with_node_list(): void
    {
        $user = $this->makeUser();
        $mission = $this->makeQuizMission(['title' => 'Kuis Hijau Harian']);
        $this->makeQuiz($mission);

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'title', 'description', 'icon', 'xp_reward',
                        'position', 'quizzes_count', 'total_questions',
                        'answered_today',
                        'is_completed_today', 'is_completed', 'is_playable_today',
                    ],
                ],
            ])
            ->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_first_node_playable_from_bottom_strict_sequential(): void
    {
        $user = $this->makeUser();
        $ids = [];
        foreach (range(1, 3) as $i) {
            $mission = $this->makeQuizMission(['title' => "Quiz {$i}"]);
            $this->makeQuiz($mission, ['question' => "Q{$i}?"]);
            $ids[] = $mission->id;
        }

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk();
        $playableIds = collect($response->json('data'))
            ->where('is_playable_today', true)
            ->pluck('id')
            ->values()
            ->all();
        // Strict 1-terbuka (anti-loncat): hanya node terendah yang terbuka.
        $this->assertEquals([$ids[0]], $playableIds);
    }

    public function test_progression_advances_after_node_finished(): void
    {
        $user = $this->makeUser();
        $missionA = $this->makeQuizMission(['title' => 'A']);
        $quizA = $this->makeQuiz($missionA, ['question' => 'QA?']);
        $missionB = $this->makeQuizMission(['title' => 'B']);
        $this->makeQuiz($missionB, ['question' => 'QB?']);
        $missionC = $this->makeQuizMission(['title' => 'C']);
        $this->makeQuiz($missionC, ['question' => 'QC?']);

        // Selesaikan node A (sesi 1 soal) dengan jawaban salah sekalipun.
        $wrongAnswer = $quizA->correct_answer === 'A' ? 'B' : 'A';
        $this->postJson('/api/saga/answer', [
            'quiz_id' => $quizA->id,
            'answer' => $wrongAnswer,
        ], $this->authHeader($user))->assertOk();

        // Progres maju strict: A selesai (review), B langsung terbuka hari
        // itu juga (kuota 2/hari belum habis), C tetap locked.
        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $playableIds = collect($nodes)->where('is_playable_today', true)->pluck('id')->values()->all();
        $this->assertEquals([$missionA->id, $missionB->id, $missionC->id], array_column($nodes, 'id'));
        $this->assertEquals([$missionA->id, $missionB->id], $playableIds);

        $nodeA = collect($nodes)->firstWhere('id', $missionA->id);
        $this->assertTrue($nodeA['is_completed_today']);
        $this->assertTrue($nodeA['is_completed']);
        $this->assertTrue(UserMission::where('user_id', $user->id)->where('status', 'rejected')->exists());
    }

    public function test_skip_day_keeps_progress_not_forfeited(): void
    {
        $user = $this->makeUser();
        $missionA = $this->makeQuizMission(['title' => 'A']);
        $quizA = $this->makeQuiz($missionA, ['question' => 'QA?']);
        $missionB = $this->makeQuizMission(['title' => 'B']);
        $this->makeQuiz($missionB, ['question' => 'QB?']);
        $missionC = $this->makeQuizMission(['title' => 'C']);
        $this->makeQuiz($missionC, ['question' => 'QC?']);

        // Selesaikan A, lalu mundurkan attempt ke kemarin (simulasi skip sehari).
        $this->postJson('/api/saga/answer', [
            'quiz_id' => $quizA->id,
            'answer' => $quizA->correct_answer,
        ], $this->authHeader($user))->assertOk();
        UserMission::where('user_id', $user->id)->update([
            'created_at' => Carbon::now('Asia/Jakarta')->subDay(),
            'updated_at' => Carbon::now('Asia/Jakarta')->subDay(),
        ]);

        // Hari ini: A tetap selesai (persisten), hanya B terbuka (strict 1),
        // C locked. Tidak ada yang hangus / di-reset ke bawah.
        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $nodeA = collect($nodes)->firstWhere('id', $missionA->id);
        $this->assertTrue($nodeA['is_completed']);
        $this->assertFalse($nodeA['is_completed_today']);
        $this->assertFalse($nodeA['is_playable_today']);
        $this->assertEquals(0, $nodeA['answered_today']);

        $playableIds = collect($nodes)->where('is_playable_today', true)->pluck('id')->values()->all();
        $this->assertEquals([$missionB->id], $playableIds);
    }

    public function test_daily_cap_two_nodes_then_next_locked(): void
    {
        $user = $this->makeUser();
        $missions = [];
        $quizzes = [];
        foreach (range(1, 4) as $i) {
            $mission = $this->makeQuizMission(['title' => "M{$i}"]);
            $quizzes[] = $this->makeQuiz($mission, ['question' => "Q{$i}?"]);
            $missions[] = $mission;
        }

        // Selesaikan 2 node hari ini → cap harian tercapai.
        foreach ([$quizzes[0], $quizzes[1]] as $quiz) {
            $this->postJson('/api/saga/answer', [
                'quiz_id' => $quiz->id,
                'answer' => $quiz->correct_answer,
            ], $this->authHeader($user))->assertOk();
        }

        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $playableIds = collect($nodes)->where('is_playable_today', true)->pluck('id')->values()->all();
        $this->assertEquals([$missions[0]->id, $missions[1]->id], $playableIds);

        $nodeC = collect($nodes)->firstWhere('id', $missions[2]->id);
        $this->assertFalse($nodeC['is_playable_today']);
        $this->assertFalse($nodeC['is_completed']);

        // Node berikut terkunci: tidak bisa lihat soal maupun jawab.
        $this->getJson(
            "/api/saga/nodes/{$missions[2]->id}/questions",
            $this->authHeader($user)
        )->assertStatus(403);
        $this->postJson('/api/saga/answer', [
            'quiz_id' => $quizzes[2]->id,
            'answer' => $quizzes[2]->correct_answer,
        ], $this->authHeader($user))->assertStatus(422);

        // Node selesai hari ini tetap bisa dibuka untuk review.
        $this->getJson(
            "/api/saga/nodes/{$missions[0]->id}/questions",
            $this->authHeader($user)
        )->assertOk();
    }

    public function test_finished_node_answerable_neither_replay_nor_farm(): void
    {
        $user = $this->makeUser();
        $missionA = $this->makeQuizMission(['title' => 'A']);
        $quizA = $this->makeQuiz($missionA, ['question' => 'QA?']);
        $missionB = $this->makeQuizMission(['title' => 'B']);
        $this->makeQuiz($missionB, ['question' => 'QB?']);

        $this->postJson('/api/saga/answer', [
            'quiz_id' => $quizA->id,
            'answer' => $quizA->correct_answer,
        ], $this->authHeader($user))->assertOk();

        // Soal yang sama tidak bisa dijawab ulang (409, tanpa XP ganda).
        $this->postJson('/api/saga/answer', [
            'quiz_id' => $quizA->id,
            'answer' => $quizA->correct_answer,
        ], $this->authHeader($user))->assertStatus(409);

        $this->assertEquals(
            intdiv($missionA->xp_reward, 1),
            $user->fresh()->wargaProfile->xp
        );
    }

    public function test_single_playable_when_only_one_mission_exists(): void
    {
        $user = $this->makeUser();
        $mission = $this->makeQuizMission(['title' => 'Only']);
        $this->makeQuiz($mission, ['question' => 'Q?']);

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk();
        $playable = collect($response->json('data'))->where('is_playable_today', true)->all();
        $this->assertCount(1, $playable);
    }

    public function test_playable_node_exposes_today_quiz_id_others_do_not(): void
    {
        $user = $this->makeUser();
        foreach (range(1, 3) as $i) {
            $mission = $this->makeQuizMission(['title' => "Quiz {$i}"]);
            $this->makeQuiz($mission, ['question' => "Q{$i}?"]);
        }

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk();
        foreach ($response->json('data') as $node) {
            if ($node['is_playable_today']) {
                $this->assertArrayHasKey('today_quiz_id', $node);
                $this->assertNotNull($node['today_quiz_id']);
            } else {
                $this->assertArrayNotHasKey('today_quiz_id', $node);
            }
        }
    }

    public function test_nodes_ordered_by_position_and_mission_id(): void
    {
        $user = $this->makeUser();
        $first = $this->makeQuizMission(['title' => 'First']);
        $second = $this->makeQuizMission(['title' => 'Second']);
        $this->makeQuiz($first);
        $this->makeQuiz($second);

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals([$first->id, $second->id], array_column($data, 'id'));
        $this->assertEquals([1, 2], array_column($data, 'position'));
    }

    public function test_excludes_inactive_and_non_quiz_missions(): void
    {
        $user = $this->makeUser();
        $active = $this->makeQuizMission(['title' => 'Active quiz']);
        $this->makeQuiz($active);
        $inactive = $this->makeQuizMission(['title' => 'Inactive quiz', 'is_active' => false]);
        $this->makeQuiz($inactive);
        Mission::create([
            'title' => 'Mobility', 'description' => 'M', 'category' => 'mobility',
            'xp_reward' => 100, 'points_reward' => 50, 'is_active' => true,
        ]);

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertEquals([$active->id], $ids);
    }

    public function test_completed_flag_after_correct_answer(): void
    {
        $user = $this->makeUser();
        $mission = $this->makeQuizMission();
        $quiz = $this->makeQuiz($mission);

        // Jawab dengan benar via endpoint answer.
        $answer = $this->postJson('/api/saga/answer', [
            'quiz_id' => $quiz->id,
            'answer' => 'B',
        ], $this->authHeader($user));
        $answer->assertOk();

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk();
        $node = collect($response->json('data'))->firstWhere('id', $mission->id);
        $this->assertNotNull($node);
        $this->assertTrue($node['is_completed_today']);
        $this->assertTrue($node['is_completed']);
        $this->assertTrue($node['is_playable_today']);
    }

    public function test_quizzes_count_reflects_questions_per_mission(): void
    {
        $user = $this->makeUser();
        $mission = $this->makeQuizMission();
        $this->makeQuiz($mission, ['question' => 'Q1?']);
        $this->makeQuiz($mission, ['question' => 'Q2?', 'order' => 2]);

        $response = $this->getJson('/api/saga/nodes', $this->authHeader($user));

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.0.quizzes_count'));
    }

    public function test_route_name_resolves(): void
    {
        $this->assertEquals('/api/saga/nodes', route('saga.nodes', [], false));
    }

    public function test_partial_attempt_does_not_advance_progression(): void
    {
        $user = $this->makeUser();
        $missionA = $this->makeQuizMission(['title' => 'A']);
        $quizA1 = $this->makeQuiz($missionA, ['question' => 'QA1?']);
        $this->makeQuiz($missionA, ['question' => 'QA2?', 'order' => 2]);
        $missionB = $this->makeQuizMission(['title' => 'B']);
        $this->makeQuiz($missionB, ['question' => 'QB?']);
        $missionC = $this->makeQuizMission(['title' => 'C']);
        $this->makeQuiz($missionC, ['question' => 'QC?']);

        // Jawab 1 dari 2 soal node A → belum selesai → progres tidak maju.
        $this->postJson('/api/saga/answer', [
            'quiz_id' => $quizA1->id,
            'answer' => $quizA1->correct_answer,
        ], $this->authHeader($user))->assertOk();

        $nodes = $this->getJson('/api/saga/nodes', $this->authHeader($user))->json('data');
        $playableIds = collect($nodes)->where('is_playable_today', true)->pluck('id')->values()->all();
        $this->assertEquals([$missionA->id], $playableIds);

        $nodeA = collect($nodes)->firstWhere('id', $missionA->id);
        $this->assertFalse($nodeA['is_completed_today']);
        $this->assertFalse($nodeA['is_completed']);
        $this->assertEquals(1, $nodeA['answered_today']);

        $nodeC = collect($nodes)->firstWhere('id', $missionC->id);
        $this->assertFalse($nodeC['is_playable_today']);
    }
}
