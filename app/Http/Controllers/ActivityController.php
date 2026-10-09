<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\PointTransaction;
use App\Models\UserMission;
use App\Models\VoucherClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityController extends Controller
{
    /**
     * GET /api/user/activities — aktivitas terbaru untuk Profil.
     * Gabungan PointTransaction (perubahan poin) + misi kuis terverifikasi
     * (reward XP tanpa transaksi poin). Urut terbaru, default 5 (maks 20).
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $limit = max(1, min((int) $request->query('limit', 5), 20));

        $items = [];

        $txs = PointTransaction::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit * 4)
            ->get();

        foreach ($txs as $tx) {
            $mapped = $this->mapTransaction($tx);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        $quizzes = UserMission::with(['mission' => fn ($q) => $q->withCount('quizzes')])
            ->where('user_id', $user->id)
            ->where('status', 'verified')
            ->whereHas('mission', fn ($q) => $q->where('category', 'quiz'))
            ->orderByDesc('id')
            ->limit($limit * 4)
            ->get();

        foreach ($quizzes as $um) {
            $response = $um->ai_gemini_response;
            if (! is_array($response) || ! isset($response['quiz_id'])) {
                continue;
            }
            $mission = $um->mission;
            if (! $mission instanceof Mission) {
                continue;
            }
            $sessionSize = max(min((int) ($mission->quizzes_count ?? 5), SagaController::SESSION_SIZE), 1);
            $items[] = [
                'id' => 'quiz-'.$um->id,
                'kind' => 'quiz',
                'title' => 'Kuis Hijau Harian',
                'delta' => intdiv((int) ($mission->xp_reward ?? 0), $sessionSize),
                'created_at' => $um->created_at?->toISOString(),
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return response()->json([
            'success' => true,
            'message' => 'Recent activities retrieved successfully.',
            'data' => array_values(array_slice($items, 0, $limit)),
        ]);
    }

    /**
     * @return array{id:string,kind:string,title:string,delta:int,created_at:?string}|null
     */
    private function mapTransaction(PointTransaction $tx): ?array
    {
        $amount = (int) $tx->amount;

        if ($tx->reference_type === UserMission::class) {
            $um = UserMission::with('mission')->find($tx->reference_id);
            $mission = $um?->mission;
            if (! $mission instanceof Mission) {
                return null;
            }
            if ($mission->category === 'waste') {
                return $this->item('tx-'.$tx->id, 'waste', 'Validasi Sampah AI', $amount, $tx);
            }
            if ($mission->category === 'mobility') {
                $activity = is_array($um->ai_gemini_response)
                    ? ($um->ai_gemini_response['activity_type'] ?? 'cycling')
                    : 'cycling';

                return $this->item(
                    'tx-'.$tx->id,
                    'mobility',
                    match ($activity) {
                        'walking' => 'Tracker Jalan Kaki',
                        'running' => 'Tracker Lari',
                        default => 'Tracker Bersepeda',
                    },
                    $amount,
                    $tx
                );
            }

            return $this->item('tx-'.$tx->id, $mission->category, $mission->title, $amount, $tx);
        }

        if ($tx->reference_type === VoucherClaim::class) {
            return $this->item('tx-'.$tx->id, 'voucher', 'Tukar Voucher UMKM', -$amount, $tx);
        }

        return $this->item(
            'tx-'.$tx->id,
            'other',
            (string) ($tx->description ?: 'Aktivitas'),
            $tx->type === 'debit' ? -$amount : $amount,
            $tx
        );
    }

    /**
     * @return array{id:string,kind:string,title:string,delta:int,created_at:?string}
     */
    private function item(string $id, string $kind, string $title, int $delta, PointTransaction $tx): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'delta' => $delta,
            'created_at' => $tx->created_at?->toISOString(),
        ];
    }
}
