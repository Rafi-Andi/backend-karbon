<?php

namespace Database\Seeders;

use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Seeder;

class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $mitraUser = User::where('email', 'kopilokal@example.com')->first();

        if (! $mitraUser) {
            return;
        }

        $mitraProfile = MitraProfile::create([
            'user_id' => $mitraUser->id,
            'nama_usaha' => 'Kopi Lokal Surabaya',
            'jenis_usaha' => 'Kedai Kopi',
            'alamat_usaha' => 'Jl. Pemuda No. 45, Gubeng, Surabaya',
            'nomor_ktp' => '3578123456789012',
            'nomor_nib' => '123456789012345',
            'foto_ktp' => null,
            'foto_nib' => null,
            'foto_toko' => null,
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Kopi Lokal Surabaya',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ]);

        $vouchers = [
            [
                'mitra_profile_id' => $mitraProfile->id,
                'title' => 'Voucher Rp 20.000 - Kopi Lokal',
                'description' => 'Tukarkan poinmu dengan voucher senilai Rp 20.000 untuk menu kopi favoritmu!',
                'image_url' => null,
                'points_cost' => 500,
                'rupiah_value' => 20000,
                'stock' => 10,
                'claimed_count' => 0,
                'expired_at' => now()->addMonths(3),
                'is_active' => true,
            ],
            [
                'mitra_profile_id' => $mitraProfile->id,
                'title' => 'Voucher Rp 15.000 - Kedai Kopi Nusantara',
                'description' => 'Potongan harga Rp 15.000 untuk semua menu di Kedai Kopi Nusantara!',
                'image_url' => null,
                'points_cost' => 350,
                'rupiah_value' => 15000,
                'stock' => 15,
                'claimed_count' => 0,
                'expired_at' => now()->addMonths(3),
                'is_active' => true,
            ],
            [
                'mitra_profile_id' => $mitraProfile->id,
                'title' => 'Voucher Rp 50.000 - Spesial Menu',
                'description' => 'Voucher spesial senilai Rp 50.000 untuk menu premium!',
                'image_url' => null,
                'points_cost' => 1000,
                'rupiah_value' => 50000,
                'stock' => 5,
                'claimed_count' => 0,
                'expired_at' => now()->addMonths(2),
                'is_active' => true,
            ],
            [
                'mitra_profile_id' => $mitraProfile->id,
                'title' => 'Donasi 1 Bibit Mangrove',
                'description' => 'Dukung reboisasi dengan mendonasikan 1 bibit pohon mangrove!',
                'image_url' => null,
                'points_cost' => 1000,
                'rupiah_value' => 25000,
                'stock' => 20,
                'claimed_count' => 0,
                'expired_at' => now()->addMonths(6),
                'is_active' => true,
            ],
            [
                'mitra_profile_id' => $mitraProfile->id,
                'title' => 'Voucher Rp 10.000 - Cemilan Sehat',
                'description' => 'Tukarkan dengan cemilan sehat di toko kami!',
                'image_url' => null,
                'points_cost' => 250,
                'rupiah_value' => 10000,
                'stock' => 20,
                'claimed_count' => 0,
                'expired_at' => now()->addMonths(3),
                'is_active' => true,
            ],
        ];

        foreach ($vouchers as $voucher) {
            Voucher::create($voucher);
        }
    }
}
