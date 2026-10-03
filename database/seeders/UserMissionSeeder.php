<?php

namespace Database\Seeders;

use App\Models\Mission;
use App\Models\User;
use App\Models\UserMission;
use Illuminate\Database\Seeder;

class UserMissionSeeder extends Seeder
{
    public function run(): void
    {
        $missions = Mission::all();

        if ($missions->isEmpty()) {
            return;
        }

        // Note: quiz missions intentionally NOT on days_ago 0 so seeded users can still test daily quiz today (WIB)
        $rafMissions = [
            ['title' => 'Pejuang Pedal 2Km', 'days_ago' => 6],
            ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 5],
            ['title' => 'Kuis Hijau Harian', 'days_ago' => 4],
            ['title' => 'Sepeda Pagi Hari', 'days_ago' => 3],
            ['title' => 'Pilah Sampah Elektronik', 'days_ago' => 2],
            ['title' => 'Kuis Hijau Harian', 'days_ago' => 1],
            ['title' => 'Jalan Kaki 3Km', 'days_ago' => 0],
        ];

        $alyaMissions = [
            ['title' => 'Pejuang Pedal 2Km', 'days_ago' => 7],
            ['title' => 'Sepeda Pagi Hari', 'days_ago' => 6],
            ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 5],
            ['title' => 'Donasi Pohon Mangrove', 'days_ago' => 4],
            ['title' => 'Kuis Hijau Harian', 'days_ago' => 3],
            ['title' => 'Pilah Sampah Elektronik', 'days_ago' => 2],
            ['title' => 'Petualangan Kuis Hijau', 'days_ago' => 1],
            ['title' => 'Jalan Kaki 3Km', 'days_ago' => 0],
        ];

        $rezaMissions = [
            ['title' => 'Pejuang Pedal 2Km', 'days_ago' => 5],
            ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 4],
            ['title' => 'Sepeda Pagi Hari', 'days_ago' => 3],
            ['title' => 'Kuis Hijau Harian', 'days_ago' => 2],
            ['title' => 'Donasi Pohon Mangrove', 'days_ago' => 1],
            ['title' => 'Jalan Kaki 3Km', 'days_ago' => 0],
        ];

        $this->createUserMissions('rafi@example.com', $rafMissions, $missions);
        $this->createUserMissions('alya@example.com', $alyaMissions, $missions);
        $this->createUserMissions('reza@example.com', $rezaMissions, $missions);
    }

    private function createUserMissions(string $email, array $missionList, $missions): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return;
        }

        foreach ($missionList as $data) {
            $mission = $missions->where('title', $data['title'])->first();

            if (! $mission) {
                continue;
            }

            UserMission::create([
                'user_id' => $user->id,
                'mission_id' => $mission->id,
                'proof_image_url' => 'seed/proof_'.$user->id.'_'.$mission->id.'.jpg',
                'status' => 'verified',
                'anti_fraud_flagged' => false,
                'created_at' => now()->subDays($data['days_ago']),
            ]);
        }
    }
}
