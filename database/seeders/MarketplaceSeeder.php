<?php

namespace Database\Seeders;

use App\Models\MitraProfile;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        $mitras = [
            [
                'name' => 'Warung Sembako Berkah',
                'email' => 'sembako@example.com',
                'nama_usaha' => 'Warung Sembako Berkah',
                'jenis_usaha' => 'Toko Kelontong',
                'alamat_usaha' => 'Jl. Rungkut No. 12, Surabaya',
                'vouchers' => [
                    [
                        'title' => 'Voucher Rp 25.000 - Sembako Hemat',
                        'description' => 'Potongan Rp 25.000 untuk belanja sembako harianmu!',
                        'category' => 'sembako',
                        'points_cost' => 500,
                        'rupiah_value' => 25000,
                        'stock' => 15,
                    ],
                    [
                        'title' => 'Voucher Isi Ulang Sabun 1L',
                        'description' => 'Isi ulang sabun cuci 1 liter, kurangi sampah plastik!',
                        'category' => 'sembako',
                        'points_cost' => 200,
                        'rupiah_value' => 12000,
                        'stock' => 25,
                    ],
                ],
            ],
            [
                'name' => 'Thrift Mantul',
                'email' => 'thrift@example.com',
                'nama_usaha' => 'Thrift Mantul',
                'jenis_usaha' => 'Toko Pakaian Bekas',
                'alamat_usaha' => 'Jl. Diponegoro No. 88, Surabaya',
                'vouchers' => [
                    [
                        'title' => 'Voucher Rp 30.000 - Thrift Fashion',
                        'description' => 'Potongan Rp 30.000 untuk fashion preloved pilihanmu!',
                        'category' => 'fashion',
                        'points_cost' => 600,
                        'rupiah_value' => 30000,
                        'stock' => 10,
                    ],
                    [
                        'title' => 'Voucher 1 Kaos Preloved',
                        'description' => 'Tukarkan dengan 1 kaos preloved layak pakai!',
                        'category' => 'fashion',
                        'points_cost' => 300,
                        'rupiah_value' => 35000,
                        'stock' => 12,
                    ],
                ],
            ],
            [
                'name' => 'Laundry EcoWash',
                'email' => 'ecowash@example.com',
                'nama_usaha' => 'Laundry EcoWash',
                'jenis_usaha' => 'Jasa Laundry',
                'alamat_usaha' => 'Jl. Manyar No. 45, Surabaya',
                'vouchers' => [
                    [
                        'title' => 'Voucher Laundry 3kg',
                        'description' => 'Cuci laundry 3kg dengan deterjen ramah lingkungan!',
                        'category' => 'jasa',
                        'points_cost' => 450,
                        'rupiah_value' => 27000,
                        'stock' => 15,
                    ],
                    [
                        'title' => 'Voucher Setrika 5kg',
                        'description' => 'Setrika 5kg pakaianmu, terima beres!',
                        'category' => 'jasa',
                        'points_cost' => 250,
                        'rupiah_value' => 15000,
                        'stock' => 20,
                    ],
                ],
            ],
            [
                'name' => 'Bengkel Sepeda Gowes',
                'email' => 'gowes@example.com',
                'nama_usaha' => 'Bengkel Sepeda Gowes',
                'jenis_usaha' => 'Bengkel Sepeda',
                'alamat_usaha' => 'Jl. Ahmad Yani No. 200, Surabaya',
                'vouchers' => [
                    [
                        'title' => 'Voucher Servis Rem Sepeda',
                        'description' => 'Servis rem sepeda biar gowes makin aman!',
                        'category' => 'transportasi',
                        'points_cost' => 350,
                        'rupiah_value' => 20000,
                        'stock' => 10,
                    ],
                    [
                        'title' => 'Voucher Sewa Sepeda Sehari',
                        'description' => 'Sewa sepeda seharian penuh untuk keliling kota!',
                        'category' => 'transportasi',
                        'points_cost' => 400,
                        'rupiah_value' => 25000,
                        'stock' => 8,
                    ],
                ],
            ],
            [
                'name' => 'Kebun Bibit Surabaya',
                'email' => 'bibit@example.com',
                'nama_usaha' => 'Kebun Bibit Surabaya',
                'jenis_usaha' => 'Komunitas Lingkungan',
                'alamat_usaha' => 'Jl. Keputih No. 5, Surabaya',
                'vouchers' => [
                    [
                        'title' => 'Donasi 1 Bibit Trembesi',
                        'description' => 'Donasikan 1 bibit pohon trembesi untuk Surabaya hijau!',
                        'category' => 'donasi',
                        'points_cost' => 800,
                        'rupiah_value' => 20000,
                        'stock' => 30,
                    ],
                    [
                        'title' => 'Donasi 5 Bibit Cabai',
                        'description' => 'Donasikan 5 bibit cabai untuk kebun komunitas!',
                        'category' => 'donasi',
                        'points_cost' => 300,
                        'rupiah_value' => 15000,
                        'stock' => 30,
                    ],
                ],
            ],
        ];

        foreach ($mitras as $i => $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => '+628200000'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'password' => Hash::make('SecurePassword123!'),
                    'role' => 'mitra',
                    'kota' => 'Surabaya',
                    'kecamatan' => 'Gubeng',
                    'kelurahan' => 'Mojo',
                    'rt' => '005',
                    'rw' => '02',
                    'is_active' => true,
                ]
            );

            $profile = MitraProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'nama_usaha' => $data['nama_usaha'],
                    'jenis_usaha' => $data['jenis_usaha'],
                    'alamat_usaha' => $data['alamat_usaha'],
                    'nomor_ktp' => '35781234567890'.str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT),
                    'nomor_nib' => '987654321098'.str_pad((string) ($i + 10), 3, '0', STR_PAD_LEFT),
                    'foto_ktp' => null,
                    'foto_nib' => null,
                    'foto_toko' => null,
                    'nama_bank' => 'Bank BCA',
                    'nomor_rekening' => '98765432'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'nama_pemilik_rekening' => $data['nama_usaha'],
                    'status_verifikasi' => 'verified',
                    'is_active' => true,
                    'balance' => 0,
                ]
            );

            foreach ($data['vouchers'] as $voucher) {
                Voucher::firstOrCreate(
                    ['title' => $voucher['title']],
                    [
                        'mitra_profile_id' => $profile->id,
                        'description' => $voucher['description'],
                        'category' => $voucher['category'],
                        'image_url' => null,
                        'points_cost' => $voucher['points_cost'],
                        'rupiah_value' => $voucher['rupiah_value'],
                        'stock' => $voucher['stock'],
                        'claimed_count' => 0,
                        'expired_at' => now()->addMonths(3),
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
