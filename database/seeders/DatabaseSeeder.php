<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            AdminSeeder::class,
            MissionSeeder::class,
            QuizSeeder::class,
            VoucherSeeder::class,
            MarketplaceSeeder::class,
            DonationCampaignSeeder::class,
            UserMissionSeeder::class,
            LeaderboardSeeder::class,
        ]);
    }
}
