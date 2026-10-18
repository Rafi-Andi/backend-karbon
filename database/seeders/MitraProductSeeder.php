<?php

namespace Database\Seeders;

use App\Models\DonationCampaign;
use App\Models\MitraProduct;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Seeder;

/**
 * Produk mitra untuk alur "build reward" (voucher pendanaan).
 *
 * Sejak alur baru, voucher marketplace dibuat admin DARI produk mitra
 * (harga fix mitra → poin otomatis ceil(harga/40), lihat EcoRate).
 * Seeder ini melengkapi data demo:
 *   1. mengisi 19 produk untuk 6 mitra seeder (semua mitra sudah verified,
 *      jadi otomatis "fundable" di picker admin);
 *   2. memetakan 15 produk ke voucher era lama yang masih tanpa produk
 *      (backfill `mitra_product_id` — hanya yang masih NULL, tidak
 *      menimpa voucher yang sudah ter-link);
 *   3. menyisakan 4 produk baru tanpa voucher supaya admin bisa langsung
 *      mencoba membuat reward baru dari nol;
 *   4. memastikan campaign "Dana Hijau KarbonKita" punya dana tersedia
 *      minimal Rp 10.000.000 untuk dialokasikan saat build reward.
 *
 * Idempotent — aman dijalankan berkali-kali, termasuk pada DB yang sudah
 * ter-seed (tidak perlu migrate:fresh). Data produk & link voucher tidak
 * pernah diduplikasi, dan collected_amount hanya dinaikkan bila kurang.
 *
 * Jalankan di DB yang sudah ter-seed:
 *   docker compose exec app php artisan db:seed --class=MitraProductSeeder --force
 */
class MitraProductSeeder extends Seeder
{
    /** Dana campaign yang selalu disisakan siap dialokasikan (demo). */
    private const CAMPAIGN_AVAILABLE = 10000000;

    /** Slug campaign pendanaan yang diisi dana demo. */
    private const CAMPAIGN_SLUG = 'dana-hijau-karbonkita';

    public function run(): void
    {
        foreach ($this->products() as $mitra) {
            $profile = User::where('email', $mitra['email'])->first()?->mitraProfile;

            if (! $profile) {
                $this->command?->warn(
                    "MitraProductSeeder dilewati: mitra {$mitra['email']} tidak ditemukan."
                );

                continue;
            }

            foreach ($mitra['products'] as $entry) {
                $product = MitraProduct::firstOrCreate(
                    [
                        'mitra_profile_id' => $profile->id,
                        'title' => $entry['title'],
                    ],
                    [
                        'description' => $entry['description'],
                        'category' => $entry['category'],
                        'rupiah_value' => $entry['rupiah_value'],
                        'is_active' => true,
                    ]
                );

                // Backfill: voucher seeder era lama (dibuat sebelum ada
                // mitra_products) disambungkan ke produk pasangannya.
                // Hanya yang masih NULL agar tidak menimpa link yang sudah ada.
                if (isset($entry['legacy_voucher'])) {
                    Voucher::where('mitra_profile_id', $profile->id)
                        ->whereNull('mitra_product_id')
                        ->where('title', $entry['legacy_voucher'])
                        ->update(['mitra_product_id' => $product->id]);
                }
            }
        }

        $this->ensureCampaignFunds();
    }

    /**
     * Pastikan campaign punya dana siap dialokasikan untuk demo build
     * reward. Hanya menambah bila kurang — collected_amount tidak pernah
     * diturunkan — dan dana yang sudah terkunci (allocated_amount) selalu
     * dihitung ulang, jadi seeder bisa dipakai sebagai "isi ulang dana".
     */
    private function ensureCampaignFunds(): void
    {
        $campaign = DonationCampaign::where('slug', self::CAMPAIGN_SLUG)->first();

        if (! $campaign) {
            $this->command?->warn(
                'MitraProductSeeder: campaign '.self::CAMPAIGN_SLUG.' tidak ditemukan, dana demo dilewati.'
            );

            return;
        }

        $target = (float) $campaign->allocated_amount + self::CAMPAIGN_AVAILABLE;

        if ((float) $campaign->collected_amount < $target) {
            $campaign->update(['collected_amount' => $target]);
        }
    }

    /**
     * Data produk per mitra seeder.
     *
     * `legacy_voucher` = judul voucher lama yang harus di-link ke produk
     * ini (null/absent = produk baru, tanpa voucher).
     *
     * @return list<array{email: string, products: list<array{title: string, description: string, category: string, rupiah_value: int, legacy_voucher?: string}>}>
     */
    private function products(): array
    {
        return [
            [
                'email' => 'kopilokal@example.com',
                'products' => [
                    [
                        'title' => 'Kopi Susu Gula Aren',
                        'description' => 'Kopi susu gula aren signature Kopi Lokal Surabaya.',
                        'category' => 'kuliner',
                        'rupiah_value' => 20000,
                        'legacy_voucher' => 'Voucher Rp 20.000 - Kopi Lokal',
                    ],
                    [
                        'title' => 'Kopi Tubruk Spesial',
                        'description' => 'Kopi tubruk robusta pilihan, diseduh dadakan.',
                        'category' => 'kuliner',
                        'rupiah_value' => 15000,
                        'legacy_voucher' => 'Voucher Rp 15.000 - Kedai Kopi Nusantara',
                    ],
                    [
                        'title' => 'Paket Spesial Menu Kopi',
                        'description' => 'Paket menu kopi premium plus snack untuk dua orang.',
                        'category' => 'kuliner',
                        'rupiah_value' => 50000,
                        'legacy_voucher' => 'Voucher Rp 50.000 - Spesial Menu',
                    ],
                    [
                        'title' => 'Bibit Mangrove untuk Donasi',
                        'description' => 'Satu bibit mangrove untuk program reboisasi pesisir.',
                        'category' => 'donasi',
                        'rupiah_value' => 25000,
                        'legacy_voucher' => 'Donasi 1 Bibit Mangrove',
                    ],
                    [
                        'title' => 'Cemilan Sehat Rumahan',
                        'description' => 'Aneka cemilan sehat buatan rumahan.',
                        'category' => 'kuliner',
                        'rupiah_value' => 10000,
                        'legacy_voucher' => 'Voucher Rp 10.000 - Cemilan Sehat',
                    ],
                    [
                        'title' => 'Cold Brew Botol 1L',
                        'description' => 'Cold brew botol 1 liter, siap diminum di rumah.',
                        'category' => 'kuliner',
                        'rupiah_value' => 30000,
                    ],
                    [
                        'title' => 'Biji Kopi Arabika 250g',
                        'description' => 'Biji kopi arabika sangrai kemasan 250 gram.',
                        'category' => 'kuliner',
                        'rupiah_value' => 45000,
                    ],
                ],
            ],
            [
                'email' => 'sembako@example.com',
                'products' => [
                    [
                        'title' => 'Paket Sembako Hemat',
                        'description' => 'Paket sembako harian berisi kebutuhan pokok pilihan.',
                        'category' => 'sembako',
                        'rupiah_value' => 25000,
                        'legacy_voucher' => 'Voucher Rp 25.000 - Sembako Hemat',
                    ],
                    [
                        'title' => 'Sabun Cuci Isi Ulang 1L',
                        'description' => 'Sabun cuci piring isi ulang 1 liter — bawa wadahmu sendiri.',
                        'category' => 'sembako',
                        'rupiah_value' => 12000,
                        'legacy_voucher' => 'Voucher Isi Ulang Sabun 1L',
                    ],
                    [
                        'title' => 'Beras Premium 5kg',
                        'description' => 'Beras premium pulen kemasan 5 kilogram.',
                        'category' => 'sembako',
                        'rupiah_value' => 65000,
                    ],
                ],
            ],
            [
                'email' => 'thrift@example.com',
                'products' => [
                    [
                        'title' => 'Kaos Preloved Pilihan',
                        'description' => 'Kaos preloved layak pakai, bebas pilih sendiri.',
                        'category' => 'fashion',
                        'rupiah_value' => 30000,
                        'legacy_voucher' => 'Voucher Rp 30.000 - Thrift Fashion',
                    ],
                    [
                        'title' => 'Kaos Preloved Layak Pakai',
                        'description' => 'Satu kaos preloved layak pakai untuk gaya sustainable.',
                        'category' => 'fashion',
                        'rupiah_value' => 35000,
                        'legacy_voucher' => 'Voucher 1 Kaos Preloved',
                    ],
                ],
            ],
            [
                'email' => 'ecowash@example.com',
                'products' => [
                    [
                        'title' => 'Laundry Reguler 3kg',
                        'description' => 'Cuci laundry 3 kg dengan deterjen ramah lingkungan.',
                        'category' => 'jasa',
                        'rupiah_value' => 27000,
                        'legacy_voucher' => 'Voucher Laundry 3kg',
                    ],
                    [
                        'title' => 'Setrika Express 5kg',
                        'description' => 'Setrika 5 kg pakaian, selesai hari yang sama.',
                        'category' => 'jasa',
                        'rupiah_value' => 15000,
                        'legacy_voucher' => 'Voucher Setrika 5kg',
                    ],
                    [
                        'title' => 'Cuci Sepatu Premium',
                        'description' => 'Cuci sepatu premium, bersih sampai ke sol.',
                        'category' => 'jasa',
                        'rupiah_value' => 35000,
                    ],
                ],
            ],
            [
                'email' => 'gowes@example.com',
                'products' => [
                    [
                        'title' => 'Servis Rem & Tune Up',
                        'description' => 'Servis rem plus tune up ringan sepeda kesayanganmu.',
                        'category' => 'transportasi',
                        'rupiah_value' => 20000,
                        'legacy_voucher' => 'Voucher Servis Rem Sepeda',
                    ],
                    [
                        'title' => 'Sewa Sepeda Sehari',
                        'description' => 'Sewa sepeda seharian penuh untuk keliling kota.',
                        'category' => 'transportasi',
                        'rupiah_value' => 25000,
                        'legacy_voucher' => 'Voucher Sewa Sepeda Sehari',
                    ],
                ],
            ],
            [
                'email' => 'bibit@example.com',
                'products' => [
                    [
                        'title' => 'Bibit Trembesi (1 bibit)',
                        'description' => 'Satu bibit trembesi untuk penghijauan kota.',
                        'category' => 'donasi',
                        'rupiah_value' => 20000,
                        'legacy_voucher' => 'Donasi 1 Bibit Trembesi',
                    ],
                    [
                        'title' => 'Bibit Cabai (5 bibit)',
                        'description' => 'Lima bibit cabai untuk kebun komunitas.',
                        'category' => 'donasi',
                        'rupiah_value' => 15000,
                        'legacy_voucher' => 'Donasi 5 Bibit Cabai',
                    ],
                ],
            ],
        ];
    }
}
