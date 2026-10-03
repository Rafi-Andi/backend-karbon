<?php

namespace App\Http\Controllers;

use App\Http\Resources\DashboardResource;
use App\Models\Mission;
use App\Models\UserMission;
use App\Models\WargaProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $profile = WargaProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'User profile not found.',
            ], 404);
        }

        $rankPercentage = $this->calculateRankPercentage($profile->xp);
        $dailyMissions = $this->getDailyMissions($user->id);
        $leaderboardPreview = $this->getLeaderboardPreview($user);

        $dashboardData = [
            'profile' => $profile,
            'rank_percentage' => $rankPercentage,
            'daily_missions' => $dailyMissions,
            'leaderboard_preview' => $leaderboardPreview,
        ];

        $dashboardData = array_merge($dashboardData, $user->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully.',
            'data' => (new DashboardResource($dashboardData))->resolve(),
        ]);
    }

    private function calculateRankPercentage(int $userXp): float
    {
        $totalUsers = WargaProfile::count();

        if ($totalUsers <= 1) {
            return 100.0;
        }

        $usersAbove = WargaProfile::where('xp', '>', $userXp)->count();

        return round(($usersAbove / $totalUsers) * 100, 1);
    }

    private function getDailyMissions(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        $todayMissions = UserMission::where('user_id', $userId)
            ->whereDate('created_at', now()->toDateString())
            ->pluck('mission_id');

        $missions = Mission::where('is_active', true)
            ->whereNotIn('id', $todayMissions)
            ->inRandomOrder()
            ->limit(3)
            ->get();

        return $missions;
    }

    private function getLeaderboardPreview($user): \Illuminate\Support\Collection
    {
        $topUsers = DB::table('users')
            ->join('warga_profiles', 'users.id', '=', 'warga_profiles.user_id')
            ->select(
                'users.id as user_id',
                'users.name',
                'users.rt',
                'users.rw',
                'warga_profiles.xp as total_xp',
                'warga_profiles.level'
            )
            ->where('users.rt', $user->rt)
            ->where('users.rw', $user->rw)
            ->where('users.is_active', true)
            ->orderBy('warga_profiles.xp', 'desc')
            ->limit(3)
            ->get()
            ->map(function ($item, $key) {
                $item->rank = $key + 1;
                $item->avatar = null;

                return $item;
            });

        return $topUsers;
    }
}
