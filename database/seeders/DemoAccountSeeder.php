<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WargaProfile;
use App\Services\LevelService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun demo untuk menguji penukaran voucher/reward berulang kali.
 *
 * Idempotent — aman dijalankan berkali-kali, termasuk pada DB yang sudah
 * ter-seed (tidak perlu migrate:fresh). Setiap kali dijalankan:
 *   1. memastikan akun `demo@example.com` ada (password sama dengan akun
 *      demo lain: SecurePassword123!) beserta profil warga-nya;
 *   2. mengembalikan saldo poin demo ke 120.000 — seluruh stok 15 voucher
 *      aktif (5 dari VoucherSeeder + 10 dari MarketplaceSeeder) bernilai
 *      113.800 poin jika diklaim habis, jadi saldo ini cukup untuk
 *      menukar SEMUA voucher yang tersedia dan masih ada sisa;
 *   3. mengembalikan saldo poin akun Rafi ke 10.000.
 *
 * Level demo sengaja di tengah tier (Earth Keeper 5, progres 50% menuju
 * level 6) agar profil tidak terlihat "tamat" di tier terakhir (Warrior).
 *
 * Reset saldo demo kapan saja:
 *   docker compose exec app php artisan db:seed --class=DemoAccountSeeder --force
 */
class DemoAccountSeeder extends Seeder
{
    private const DEMO_POINTS = 120000;

    private const RAFI_POINTS = 10000;

    public function run(): void
    {
        $demo = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            [
                'name' => 'Warga Demo',
                'phone' => '+628123456793',
                'password' => Hash::make('SecurePassword123!'),
                'role' => 'warga',
                'kota' => 'Surabaya',
                'kecamatan' => 'Gubeng',
                'kelurahan' => 'Mojo',
                'rt' => '005',
                'rw' => '02',
                'is_active' => true,
            ]
        );

        WargaProfile::updateOrCreate(
            ['user_id' => $demo->id],
            [
                // Sinkron via LevelService: 1.000 XP => Earth Keeper 5
                // (tier tengah) dengan progres 50% ke level berikutnya.
                'level' => LevelService::resolveLevel(1000),
                'xp' => 1000,
                'eco_points' => self::DEMO_POINTS,
                'streak_days' => 12,
                'total_distance_km' => 88.50,
                'total_waste_kg' => 24.80,
                'total_carbon_saved_kg' => 35.20,
            ]
        );

        // Top-up akun Rafi untuk DB yang sudah ter-seed sebelum seeder ini
        // ada (UserSeeder hanya berjalan saat DB masih kosong). Hanya kolom
        // poin yang disentuh; statistik lain milik user tidak diubah.
        $rafi = User::where('email', 'rafi@example.com')->first();

        if ($rafi) {
            WargaProfile::where('user_id', $rafi->id)
                ->update(['eco_points' => self::RAFI_POINTS]);
        }
    }
}
