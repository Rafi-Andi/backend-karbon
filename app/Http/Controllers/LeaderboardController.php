<?php

namespace App\Http\Controllers;

use App\Http\Resources\LeaderboardResource;
use App\Models\User;
use App\Models\WargaProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeaderboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => 'sometimes|string|in:rt,rw',
            'timeframe' => 'sometimes|string|in:weekly,monthly',
        ]);

        $scope = $request->get('scope', 'rt');
        $timeframe = $request->get('timeframe', 'weekly');

        $user = Auth::user();

        $dateRange = $this->getDateRange($timeframe);

        $rankings = $this->getRankings($scope, $dateRange, $user);

        $currentUserRank = $this->getCurrentUserRank($user, $scope, $dateRange);

        $leaderboardData = [
            'scope' => $scope,
            'timeframe' => $timeframe,
            'rankings' => $rankings,
            'current_user' => $currentUserRank,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Leaderboard retrieved successfully.',
            'data' => (new LeaderboardResource($leaderboardData))->resolve(),
        ]);
    }

    private function getDateRange(string $timeframe): array
    {
        $now = now();

        return match ($timeframe) {
            'weekly' => [
                'start' => $now->copy()->subWeek(),
                'end' => $now,
            ],
            'monthly' => [
                'start' => $now->copy()->subMonth(),
                'end' => $now,
            ],
            default => [
                'start' => $now->copy()->subWeek(),
                'end' => $now,
            ],
        };
    }

    private function getRankings(string $scope, array $dateRange, User $user): \Illuminate\Support\Collection
    {
        $query = DB::table('user_missions')
            ->join('missions', 'user_missions.mission_id', '=', 'missions.id')
            ->join('users', 'user_missions.user_id', '=', 'users.id')
            ->leftJoin('warga_profiles', 'users.id', '=', 'warga_profiles.user_id')
            ->select(
                'users.id as user_id',
                'users.name',
                'users.rt',
                'users.rw',
                DB::raw('SUM(missions.xp_reward) as total_xp'),
                DB::raw('MAX(warga_profiles.level) as level')
            )
            ->where('user_missions.status', 'verified')
            ->whereBetween('user_missions.created_at', [$dateRange['start'], $dateRange['end']])
            ->where('users.is_active', true)
            ->groupBy('users.id', 'users.name', 'users.rt', 'users.rw', 'warga_profiles.level');

        if ($scope === 'rt') {
            $query->where('users.rt', $user->rt)
                ->where('users.rw', $user->rw);
        } else {
            $query->where('users.rw', $user->rw);
        }

        $rankings = $query->orderBy('total_xp', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item, $key) {
                $item->rank = $key + 1;
                $item->avatar = null;

                return $item;
            });

        return $rankings;
    }

    private function getCurrentUserRank(User $user, string $scope, array $dateRange): ?object
    {
        $userXp = DB::table('user_missions')
            ->join('missions', 'user_missions.mission_id', '=', 'missions.id')
            ->where('user_missions.user_id', $user->id)
            ->where('user_missions.status', 'verified')
            ->whereBetween('user_missions.created_at', [$dateRange['start'], $dateRange['end']])
            ->sum('missions.xp_reward');

        $subQuery = DB::table('user_missions')
            ->join('missions', 'user_missions.mission_id', '=', 'missions.id')
            ->join('users', 'user_missions.user_id', '=', 'users.id')
            ->select(
                'users.id as user_id',
                DB::raw('SUM(missions.xp_reward) as total_xp')
            )
            ->where('user_missions.status', 'verified')
            ->whereBetween('user_missions.created_at', [$dateRange['start'], $dateRange['end']])
            ->where('users.is_active', true)
            ->groupBy('users.id');

        if ($scope === 'rt') {
            $subQuery->where('users.rt', $user->rt)
                ->where('users.rw', $user->rw);
        } else {
            $subQuery->where('users.rw', $user->rw);
        }

        $usersAbove = DB::table('users')
            ->joinSub($subQuery, 'user_xp', function ($join) {
                $join->on('users.id', '=', 'user_xp.user_id');
            })
            ->where('user_xp.total_xp', '>', $userXp)
            ->count();

        $rank = $usersAbove + 1;

        $profile = WargaProfile::where('user_id', $user->id)->first();

        return (object) [
            'rank' => $rank,
            'user_id' => $user->id,
            'name' => $user->name,
            'rt' => $user->rt,
            'rw' => $user->rw,
            'total_xp' => $userXp,
            'level' => $profile->level ?? 'Earth Newbie',
            'avatar' => null,
        ];
    }
}
