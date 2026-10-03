<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WargaProfile;
use App\Services\LevelService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Rafi: 7 misi selesai
        // Pejuang Pedal 2Km (150xp, 50pt) + Pahlawan Plastik (300xp, 100pt) + Kuis Hijau (50xp, 20pt)
        // + Sepeda Pagi (200xp, 75pt) + Pilah Elektronik (250xp, 80pt) + Jalan Kaki (100xp, 40pt)
        // + Petualangan Kuis (30xp, 15pt)
        // Total: 1080 xp, 380 points
        $rafi = User::create([
            'name' => 'Moch. Rafi Andi',
            'email' => 'rafi@example.com',
            'phone' => '+628123456789',
            'password' => Hash::make('SecurePassword123!'),
            'role' => 'warga',
            'kota' => 'Surabaya',
            'kecamatan' => 'Gubeng',
            'kelurahan' => 'Mojo',
            'rt' => '005',
            'rw' => '02',
            'is_active' => true,
        ]);

        WargaProfile::create([
            'user_id' => $rafi->id,
            // Sinkron dengan LevelService: 1080 XP => Earth Keeper 5.
            // Jangan hardcode label Warrior agar tidak "turun" saat kuis
            // menimpa via LevelService::resolveLevel().
            'level' => LevelService::resolveLevel(1080),
            'xp' => 1080,
            'eco_points' => 380,
            'streak_days' => 7,
            'total_distance_km' => 45.50,
            'total_waste_kg' => 12.30,
            'total_carbon_saved_kg' => 18.75,
        ]);

        // Alya: 8 misi selesai
        // Pejuang Pedal (150xp, 50pt) + Sepeda Pagi (200xp, 75pt) + Pahlawan Plastik (300xp, 100pt)
        // + Donasi Mangrove (100xp, 25pt) + Kuis Hijau (50xp, 20pt) + Pilah Elektronik (250xp, 80pt)
        // + Jalan Kaki (100xp, 40pt) + Petualangan Kuis (30xp, 15pt)
        // Total: 1180 xp, 405 points
        $alya = User::create([
            'name' => 'Alya Nabila',
            'email' => 'alya@example.com',
            'phone' => '+628123456790',
            'password' => Hash::make('SecurePassword123!'),
            'role' => 'warga',
            'kota' => 'Surabaya',
            'kecamatan' => 'Gubeng',
            'kelurahan' => 'Mojo',
            'rt' => '005',
            'rw' => '02',
            'is_active' => true,
        ]);

        WargaProfile::create([
            'user_id' => $alya->id,
            // Sinkron dengan LevelService: 1180 XP => Earth Keeper 5.
            'level' => LevelService::resolveLevel(1180),
            'xp' => 1180,
            'eco_points' => 405,
            'streak_days' => 8,
            'total_distance_km' => 62.30,
            'total_waste_kg' => 15.80,
            'total_carbon_saved_kg' => 24.20,
        ]);

        // Reza: 6 misi selesai
        // Pejuang Pedal (150xp, 50pt) + Pahlawan Plastik (300xp, 100pt) + Sepeda Pagi (200xp, 75pt)
        // + Kuis Hijau (50xp, 20pt) + Donasi Mangrove (100xp, 25pt) + Petualangan Kuis (30xp, 15pt)
        // Total: 830 xp, 285 points
        $reza = User::create([
            'name' => 'Reza Rahardian',
            'email' => 'reza@example.com',
            'phone' => '+628123456791',
            'password' => Hash::make('SecurePassword123!'),
            'role' => 'warga',
            'kota' => 'Surabaya',
            'kecamatan' => 'Gubeng',
            'kelurahan' => 'Mojo',
            'rt' => '005',
            'rw' => '02',
            'is_active' => true,
        ]);

        WargaProfile::create([
            'user_id' => $reza->id,
            // Sinkron dengan LevelService: 830 XP => Earth Keeper 5.
            'level' => LevelService::resolveLevel(830),
            'xp' => 830,
            'eco_points' => 285,
            'streak_days' => 6,
            'total_distance_km' => 38.40,
            'total_waste_kg' => 9.60,
            'total_carbon_saved_kg' => 14.80,
        ]);

        User::create([
            'name' => 'Kopi Lokal Surabaya',
            'email' => 'kopilokal@example.com',
            'phone' => '+628123456792',
            'password' => Hash::make('SecurePassword123!'),
            'role' => 'mitra',
            'kota' => 'Surabaya',
            'kecamatan' => 'Gubeng',
            'kelurahan' => 'Mojo',
            'rt' => '005',
            'rw' => '02',
            'is_active' => true,
        ]);
    }
}
