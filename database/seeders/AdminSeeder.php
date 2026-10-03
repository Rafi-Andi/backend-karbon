<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@karbonkita.id'],
            [
                'name' => 'KarbonKita Admin',
                'phone' => '+628000000001',
                'password' => Hash::make('SecurePassword123!'),
                'role' => 'admin',
                'kota' => 'Surabaya',
                'kecamatan' => 'Gubeng',
                'kelurahan' => 'Mojo',
                'rt' => '001',
                'rw' => '01',
                'is_active' => true,
            ]
        );
    }
}
