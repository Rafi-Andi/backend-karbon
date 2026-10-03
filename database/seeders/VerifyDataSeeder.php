<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserMission;
use Illuminate\Database\Seeder;

class VerifyDataSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('role', 'warga')->get();

        echo "\n=== DATA VERIFICATION ===\n\n";

        foreach ($users as $user) {
            $profile = $user->wargaProfile;
            $completedMissions = UserMission::where('user_id', $user->id)
                ->where('status', 'verified')
                ->count();

            $xpFromMissions = UserMission::where('user_id', $user->id)
                ->where('status', 'verified')
                ->join('missions', 'user_missions.mission_id', '=', 'missions.id')
                ->sum('missions.xp_reward');

            $pointsFromMissions = UserMission::where('user_id', $user->id)
                ->where('status', 'verified')
                ->join('missions', 'user_missions.mission_id', '=', 'missions.id')
                ->sum('missions.points_reward');

            $xpMatch = ($profile->xp == $xpFromMissions) ? '✅' : '❌';
            $pointsMatch = ($profile->eco_points == $pointsFromMissions) ? '✅' : '❌';

            echo "User: {$user->name} ({$user->email})\n";
            echo "  Missions completed: {$completedMissions}\n";
            echo "  Profile XP: {$profile->xp} | From missions: {$xpFromMissions} {$xpMatch}\n";
            echo "  Profile Points: {$profile->eco_points} | From missions: {$pointsFromMissions} {$pointsMatch}\n\n";
        }
    }
}
