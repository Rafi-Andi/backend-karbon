<?php

namespace Database\Seeders;

use App\Models\DonationCampaign;
use Illuminate\Database\Seeder;

class DonationCampaignSeeder extends Seeder
{
    public function run(): void
    {
        DonationCampaign::firstOrCreate(
            ['slug' => 'dana-hijau-karbonkita'],
            [
                'title' => 'Dana Hijau KarbonKita',
                'description' => 'Pool donasi umum untuk mendanai voucher reward warga. Donasi CSR, komunitas, maupun individu disalurkan admin menjadi voucher UMKM.',
                'target_amount' => 100000000,
                'status' => 'active',
                'started_at' => now(),
            ]
        );
    }
}
