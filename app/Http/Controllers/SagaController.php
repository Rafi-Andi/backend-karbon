<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuizAnswerRequest;
use App\Http\Resources\QuizResource;
use App\Http\Resources\SagaNodeResource;
use App\Models\Mission;
use App\Models\Quiz;
use App\Models\UserMission;
use App\Models\WargaProfile;
use App\Services\LevelService;
use App\Services\StreakService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SagaController extends Controller
{
    /**
     * Jumlah soal per sesi node (fixed). Pastikan xp_reward misi quiz
     * habis dibagi angka ini agar XP bagi-rata bulat.
     */
    public const SESSION_SIZE = 5;

    /**
     * Kuota main harian: maksimal 2 node selesai per hari (1 node = 1 sesi
     * 5 soal). Unlock berurutan strict: hanya 1 node berikutnya yang belum
     * selesai yang terbuka; selesai node 1 hari ini langsung membuka node 2
     * hari itu juga selama kuota belum habis. Skip sehari tidak hangus —
     * progres diam di node terakhir (berbasis progres, bukan rotasi tanggal).
     */
    public const DAILY_PLAYABLE_NODES = 2;

    /** Kuota node selesai per hari (dipakai untuk cap harian). */
    public const DAILY_QUOTA = 2;

    /** Jumlah node berikutnya yang dibuka berurutan (anti-loncat = 1). */
    public const SEQUENTIAL_OPEN = 1;

    /**
     * GET /api/saga/quizzes — kuis harian (legacy, 1 soal).
     * Mengembalikan soal dari node playable pertama agar konsisten dengan
     * nodes(). Bila tidak ada yang playable (semua selesai / cap harian
     * tercapai), tampilkan node progres terakhir sebagai selesai.
     * Aturan sesi: benar = +xp_reward/SESSION_SIZE; salah = hangus
     * (soal terkunci hari ini, 409 bila diulang). Node selesai saat
     * semua soal sesi terjawab. Hanya XP, tanpa eco_points.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $todayWib = Carbon::now(StreakService::TZ)->toDateString();

        $playableIds = $this->resolvePlayableMissionIds($user->id, $todayWib);
        $orderedIds = $this->orderedQuizMissionIds();
        $targetId = $playableIds[0] ?? $orderedIds[0] ?? null;

        if ($targetId === null) {
            return response()->json([
                'success' => false,
                'message' => 'No quizzes available.',
            ], 404);
        }

        $quiz = $this->resolveMissionTodayQuiz($user->id, $targetId, $todayWib);

        if (! $quiz) {
            return response()->json([
                'success' => false,
                'message' => 'No quizzes available.',
            ], 404);
        }

        $completedTodayIds = $this->completedTodayMissionIds($user->id, $orderedIds, $todayWib);
        $isCompletedToday = in_array($targetId, $completedTodayIds, true)
            || $this->isMissionEverCompleted($user->id, $targetId);

        // If completed, show_answer true; otherwise hide
        $resource = new QuizResource([
            'quiz' => $quiz,
            'is_completed_today' => $isCompletedToday,
            'show_answer' => $isCompletedToday,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Quizzes retrieved successfully.',
            'data' => $resource->resolve(),
        ]);
    }

    /**
     * POST /api/saga/answer — {quiz_id, answer: A/B/C/D}
     * Boleh retry sampai benar. Setelah verified hari ini, 409.
     * Hanya XP, tanpa eco_points.
     */
    public function answer(QuizAnswerRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();
        $todayWib = Carbon::now(StreakService::TZ)->toDateString();

        $quiz = Quiz::with('mission')->find($validated['quiz_id']);
        $mission = $quiz->mission;

        if (! $mission || $mission->category !== 'quiz' || ! $mission->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not available.',
            ], 422);
        }

        $playableMissionIds = $this->resolvePlayableMissionIds($user->id, $todayWib);

        if (! in_array($mission->id, $playableMissionIds, true)) {
            if ($this->isMissionEverCompleted($user->id, $mission->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Node already completed.',
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'This node is locked. Complete previous nodes to unlock it.',
            ], 422);
        }

        $sessionIds = $this->sessionQuizIds($user->id, $mission->id, $todayWib);

        if (! in_array($quiz->id, $sessionIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz is not part of today\'s session.',
            ], 422);
        }

        // Sudah dijawab hari ini (benar maupun salah) → hangus, tidak bisa diulang.
        if ($this->isQuizAnsweredToday($user->id, $mission->id, $quiz->id, $todayWib)) {
            return response()->json([
                'success' => false,
                'message' => 'You have already answered this question today.',
            ], 409);
        }

        $isCorrect = strtoupper($validated['answer']) === strtoupper($quiz->correct_answer);
        $xpPerQuestion = intdiv($mission->xp_reward ?? 0, max(count($sessionIds), 1));

        $result = DB::transaction(function () use ($user, $quiz, $mission, $isCorrect, $xpPerQuestion, $todayWib) {
            // Cek ulang dalam transaksi (anti race condition submit ganda).
            if ($this->isQuizAnsweredToday($user->id, $mission->id, $quiz->id, $todayWib)) {
                return ['duplicate' => true];
            }

            $profile = WargaProfile::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $profile) {
                throw new \Exception('User profile not found.');
            }

            $status = $isCorrect ? 'verified' : 'rejected';

            $userMission = UserMission::create([
                'user_id' => $user->id,
                'mission_id' => $quiz->mission_id,
                'status' => $status,
                'confidence_score' => $isCorrect ? 100 : 0,
                'ai_gemini_response' => ['quiz_id' => $quiz->id, 'answer' => $isCorrect ? 'correct' : 'incorrect'],
            ]);

            if ($isCorrect) {
                $newXp = $profile->xp + $xpPerQuestion;
                $newStreak = StreakService::nextStreak($profile->last_mission_at, $profile->streak_days);
                $newLevel = LevelService::resolveLevel($newXp);

                $profile->update([
                    'xp' => $newXp,
                    'level' => $newLevel,
                    'streak_days' => $newStreak,
                    'last_mission_at' => now(),
                ]);

                return [
                    'duplicate' => false,
                    'is_correct' => true,
                    'xp_earned' => $xpPerQuestion,
                    'new_xp' => $newXp,
                    'new_level' => $newLevel,
                    'streak_days' => $newStreak,
                    'user_mission_id' => $userMission->id,
                ];
            }

            // Wrong answer — hangus: tidak ada XP dan tidak bocor kunci jawaban.
            // Client lanjut ke soal berikut; soal ini terkunci hari ini.
            return [
                'duplicate' => false,
                'is_correct' => false,
                'xp_earned' => 0,
                'user_mission_id' => $userMission->id,
            ];
        });

        if (($result['duplicate'] ?? false) === true) {
            return response()->json([
                'success' => false,
                'message' => 'You have already answered this question today.',
            ], 409);
        }

        $progress = $this->sessionProgress($user->id, $mission->id, $sessionIds, $xpPerQuestion, $todayWib);
        $result = array_merge($result, $progress);

        if ($result['is_correct']) {
            return response()->json([
                'success' => true,
                'message' => 'Correct! +'.$result['xp_earned'].' XP earned.',
                'data' => $result,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incorrect. This question is forfeited, moving to the next one!',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/saga/nodes/{id}/questions — soal sesi hari ini untuk 1 node.
     * Hanya untuk node playable (403 bila terkunci). Node yang sudah pernah
     * selesai tetap bisa dibuka untuk review. correct_answer/explanation
     * hanya dibuka untuk soal yang sudah dijawab.
     */
    public function questions(int $id): JsonResponse
    {
        $user = Auth::user();
        $todayWib = Carbon::now(StreakService::TZ)->toDateString();

        $mission = Mission::where('category', 'quiz')
            ->where('is_active', true)
            ->find($id);

        if (! $mission) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz node not found.',
            ], 404);
        }

        $playableMissionIds = $this->resolvePlayableMissionIds($user->id, $todayWib);

        if (! in_array($mission->id, $playableMissionIds, true)
            && ! $this->isMissionEverCompleted($user->id, $mission->id)) {
            return response()->json([
                'success' => false,
                'message' => 'This node is locked. Complete previous nodes to unlock it.',
            ], 403);
        }

        $sessionIds = $this->sessionQuizIds($user->id, $mission->id, $todayWib);

        $quizzes = Quiz::whereIn('id', $sessionIds)->get()
            ->sortBy(fn ($q) => array_search($q->id, $sessionIds, true))
            ->values();

        $attempts = $this->todayAttemptsByQuiz($user->id, $mission->id, $todayWib);

        $items = [];
        foreach ($quizzes as $quiz) {
            $attempt = $attempts[$quiz->id] ?? null;
            $item = [
                'id' => $quiz->id,
                'mission_id' => $quiz->mission_id,
                'question' => $quiz->question,
                'options' => $quiz->options,
                'order' => $quiz->order,
                'is_answered_today' => $attempt !== null,
                'was_correct' => $attempt ? $attempt['status'] === 'verified' : null,
            ];
            if ($attempt) {
                $item['correct_answer'] = $quiz->correct_answer;
                $item['explanation'] = $quiz->explanation;
            }
            $items[] = $item;
        }

        return response()->json([
            'success' => true,
            'message' => 'Node questions retrieved successfully.',
            'data' => [
                'mission_id' => $mission->id,
                'mission_title' => $mission->title,
                'session_date' => $todayWib,
                'total' => count($items),
                'xp_per_question' => intdiv($mission->xp_reward ?? 0, max(count($sessionIds), 1)),
                'questions' => $items,
            ],
        ]);
    }

    /**
     * GET /api/saga/nodes — daftar node peta Saga (1 node = 1 misi quiz).
     * Progres berurutan dari bawah: hanya SEQUENTIAL_OPEN (1) node
     * berikutnya yang belum selesai terbuka; sisanya locked. Kuota
     * DAILY_QUOTA (2) node selesai per hari.
     * today_quiz_id = soal sesi pertama yang belum dijawab (kompatibilitas).
     * is_completed = pernah selesai (persisten, tidak hangus bila skip hari).
     */
    public function nodes(): JsonResponse
    {
        $user = Auth::user();
        $todayWib = Carbon::now(StreakService::TZ)->toDateString();

        $playableMissionIds = $this->resolvePlayableMissionIds($user->id, $todayWib);

        $missions = Mission::where('category', 'quiz')
            ->where('is_active', true)
            ->orderBy('id')
            ->withCount('quizzes')
            ->get();

        $everCompletedIds = [];
        foreach ($missions as $mission) {
            if ($this->isMissionEverCompleted($user->id, $mission->id)) {
                $everCompletedIds[] = $mission->id;
            }
        }

        $nodes = [];
        $position = 1;
        foreach ($missions as $mission) {
            $isPlayable = in_array($mission->id, $playableMissionIds, true);
            $sessionIds = $this->sessionQuizIds($user->id, $mission->id, $todayWib);
            $attempts = $this->todayAttemptsByQuiz($user->id, $mission->id, $todayWib);
            $answered = 0;
            foreach ($sessionIds as $qid) {
                if (isset($attempts[$qid])) {
                    $answered++;
                }
            }
            $total = count($sessionIds);
            $firstUnanswered = null;
            foreach ($sessionIds as $qid) {
                if (! isset($attempts[$qid])) {
                    $firstUnanswered = $qid;
                    break;
                }
            }
            $nodes[] = (new SagaNodeResource([
                'mission' => $mission,
                'position' => $position++,
                'quizzes_count' => $mission->quizzes_count ?? 0,
                'is_completed_today' => $total > 0 && $answered >= $total,
                'is_completed' => in_array($mission->id, $everCompletedIds, true),
                'is_playable_today' => $isPlayable,
                'today_quiz_id' => $isPlayable ? $firstUnanswered : null,
                'total_questions' => $total,
                'answered_today' => $answered,
            ]))->resolve();
        }

        $completedTodayCount = 0;
        foreach ($nodes as $node) {
            if (! empty($node['is_completed_today'])) {
                $completedTodayCount++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Saga nodes retrieved successfully.',
            'data' => $nodes,
            'meta' => [
                'daily_quota' => self::DAILY_QUOTA,
                'completed_today' => $completedTodayCount,
                'remaining' => max(self::DAILY_QUOTA - $completedTodayCount, 0),
            ],
        ]);
    }

    /**
     * ID soal sesi hari ini untuk 1 misi: fixed SESSION_SIZE, deterministik
     * per user+misi+tanggal (rotasi stabil agar retry/resume soal sama).
     *
     * @return list<int>
     */
    private function sessionQuizIds(int $userId, int $missionId, string $todayWib): array
    {
        $ids = Quiz::where('mission_id', $missionId)->orderBy('id')->pluck('id')->all();

        if (count($ids) <= self::SESSION_SIZE) {
            return $ids;
        }

        $seed = abs(crc32($userId.'|'.$missionId.'|'.$todayWib));
        $offset = $seed % count($ids);
        $rotated = array_merge(array_slice($ids, $offset), array_slice($ids, 0, $offset));

        return array_slice($rotated, 0, self::SESSION_SIZE);
    }

    /**
     * Attempt terakhir hari ini per quiz (verified maupun rejected),
     * diindeks quiz_id. Filter PHP agar konsisten MySQL maupun SQLite.
     *
     * @return array<int, array{status: string}>
     */
    private function todayAttemptsByQuiz(int $userId, int $missionId, string $todayWib): array
    {
        $missions = UserMission::where('user_id', $userId)
            ->where('mission_id', $missionId)
            ->whereDate('created_at', $todayWib)
            ->orderBy('id')
            ->get();

        $attempts = [];
        foreach ($missions as $m) {
            $quizId = $m->ai_gemini_response['quiz_id'] ?? null;
            if ($quizId !== null) {
                $attempts[(int) $quizId] = ['status' => $m->status];
            }
        }

        return $attempts;
    }

    private function isQuizAnsweredToday(int $userId, int $missionId, int $quizId, string $todayWib): bool
    {
        $attempts = $this->todayAttemptsByQuiz($userId, $missionId, $todayWib);

        return isset($attempts[$quizId]);
    }

    /**
     * Progres sesi hari ini: terjawab, benar, sisa, XP sesi, dan flag selesai.
     *
     * @param  list<int>  $sessionIds
     */
    private function sessionProgress(int $userId, int $missionId, array $sessionIds, int $xpPerQuestion, string $todayWib): array
    {
        $attempts = $this->todayAttemptsByQuiz($userId, $missionId, $todayWib);

        $answered = 0;
        $correct = 0;
        foreach ($sessionIds as $qid) {
            if (isset($attempts[$qid])) {
                $answered++;
                if ($attempts[$qid]['status'] === 'verified') {
                    $correct++;
                }
            }
        }

        $total = count($sessionIds);

        return [
            'node_completed' => $total > 0 && $answered >= $total,
            'remaining' => max($total - $answered, 0),
            'session_correct' => $correct,
            'session_xp' => $correct * $xpPerQuestion,
        ];
    }

    /**
     * ID misi quiz aktif yang punya soal, urut dari bawah (id kecil =
     * position kecil). Misi tanpa soal dikecualikan dari progres agar
     * tidak menyumbat urutan unlock.
     *
     * @return list<int>
     */
    private function orderedQuizMissionIds(): array
    {
        return Mission::where('category', 'quiz')
            ->where('is_active', true)
            ->whereHas('quizzes')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Daftar mission_id playable: hanya SEQUENTIAL_OPEN (1) node berikutnya
     * yang belum selesai, urut dari bawah (anti-loncat). Node yang selesai
     * hari ini tetap terbuka (untuk review). Kuota harian DAILY_QUOTA (2):
     * bila sudah 2 node selesai hari ini, node berikut terkunci sampai besok.
     * Skip sehari tidak menghanguskan apa pun (murni berbasis progres).
     *
     * @return list<int>
     */
    private function resolvePlayableMissionIds(int $userId, string $todayWib): array
    {
        $ordered = $this->orderedQuizMissionIds();

        if (count($ordered) <= self::SEQUENTIAL_OPEN) {
            return $ordered;
        }

        $everDone = [];
        foreach ($ordered as $mid) {
            if ($this->isMissionEverCompleted($userId, $mid)) {
                $everDone[] = $mid;
            }
        }

        $next = array_values(array_diff($ordered, $everDone));
        $next = array_slice($next, 0, self::SEQUENTIAL_OPEN);

        $completedToday = $this->completedTodayMissionIds($userId, $ordered, $todayWib);
        $capReached = count($completedToday) >= self::DAILY_QUOTA;

        $playable = [];
        foreach ($ordered as $mid) {
            if (in_array($mid, $completedToday, true)) {
                $playable[] = $mid;
            } elseif (! $capReached && in_array($mid, $next, true)) {
                $playable[] = $mid;
            }
        }

        return $playable;
    }

    /**
     * Mission_id yang sesi hariannya sudah terjawab semua hari ini
     * (benar maupun salah = node selesai hari ini).
     *
     * @param  list<int>  $missionIds
     * @return list<int>
     */
    private function completedTodayMissionIds(int $userId, array $missionIds, string $todayWib): array
    {
        $done = [];
        foreach ($missionIds as $mid) {
            $sessionIds = $this->sessionQuizIds($userId, $mid, $todayWib);
            if (count($sessionIds) === 0) {
                continue;
            }
            $attempts = $this->todayAttemptsByQuiz($userId, $mid, $todayWib);
            $answered = 0;
            foreach ($sessionIds as $qid) {
                if (isset($attempts[$qid])) {
                    $answered++;
                }
            }
            if ($answered >= count($sessionIds)) {
                $done[] = $mid;
            }
        }

        return $done;
    }

    /**
     * True bila user pernah menyelesaikan 1 sesi penuh node ini pada
     * 1 tanggal (benar maupun salah). Persisten — tidak hangus bila
     * user skip sehari. Tanggal attempt dibaca dari string tersimpan
     * agar konsisten dengan filter whereDate() di query harian.
     */
    private function isMissionEverCompleted(int $userId, int $missionId): bool
    {
        return count($this->missionCompletionDates($userId, $missionId)) > 0;
    }

    /**
     * Tanggal-tanggal saat sesi node terjawab penuh.
     *
     * @return list<string>
     */
    private function missionCompletionDates(int $userId, int $missionId): array
    {
        $attempts = UserMission::where('user_id', $userId)
            ->where('mission_id', $missionId)
            ->orderBy('id')
            ->get();

        $byDate = [];
        foreach ($attempts as $attempt) {
            $quizId = $attempt->ai_gemini_response['quiz_id'] ?? null;
            if ($quizId === null) {
                continue;
            }
            $date = substr((string) $attempt->getRawOriginal('created_at'), 0, 10);
            $byDate[$date][] = (int) $quizId;
        }

        $done = [];
        foreach ($byDate as $date => $quizIds) {
            $session = $this->sessionQuizIds($userId, $missionId, $date);
            if (count($session) > 0 && count(array_diff($session, $quizIds)) === 0) {
                $done[] = $date;
            }
        }

        return $done;
    }

    /**
     * Soal representatif hari ini untuk 1 misi (dipakai endpoint legacy
     * index()). Prioritas: verified hari ini > attempt terakhir hari ini
     * > acak deterministik dalam misi itu.
     */
    private function resolveMissionTodayQuiz(int $userId, int $missionId, string $todayWib): ?Quiz
    {
        $verifiedMission = UserMission::where('user_id', $userId)
            ->where('mission_id', $missionId)
            ->where('status', 'verified')
            ->whereDate('created_at', $todayWib)
            ->latest('id')
            ->first();

        if ($verifiedMission) {
            $quizId = $verifiedMission->ai_gemini_response['quiz_id'] ?? null;
            $quiz = $quizId ? Quiz::with('mission')->find($quizId) : null;
            if ($quiz) {
                return $quiz;
            }

            return Quiz::with('mission')->where('mission_id', $missionId)->first();
        }

        $lastAttempt = UserMission::where('user_id', $userId)
            ->where('mission_id', $missionId)
            ->whereDate('created_at', $todayWib)
            ->latest('id')
            ->first();

        if ($lastAttempt) {
            $quizId = $lastAttempt->ai_gemini_response['quiz_id'] ?? null;
            $quiz = $quizId ? Quiz::with('mission')->find($quizId) : null;
            if ($quiz) {
                return $quiz;
            }

            return Quiz::with('mission')->where('mission_id', $missionId)->first();
        }

        $total = Quiz::where('mission_id', $missionId)->count();
        if ($total === 0) {
            return null;
        }

        $seed = abs(crc32($userId.'|'.$missionId.'|'.$todayWib));
        $offset = $seed % $total;

        return Quiz::with('mission')
            ->where('mission_id', $missionId)
            ->orderBy('id')
            ->offset($offset)
            ->first();
    }
}
