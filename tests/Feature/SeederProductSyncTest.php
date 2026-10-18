<?php

namespace Tests\Feature;

use App\Models\DonationCampaign;
use App\Models\MitraProduct;
use App\Models\Voucher;
use Database\Seeders\DonationCampaignSeeder;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\MitraProductSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\VoucherSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menjaga sinkronisasi data seeder dengan alur "build reward" baru:
 * reward (voucher) wajib dibuat dari produk mitra, jadi seeder harus
 * menyediakan produk fundable, link voucher lama, dan dana campaign.
 */
class SeederProductSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rantai seeder yang relevan: mitra + voucher lama → campaign → produk.
     */
    private function seedChain(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(VoucherSeeder::class);
        $this->seed(MarketplaceSeeder::class);
        $this->seed(DonationCampaignSeeder::class);
        $this->seed(MitraProductSeeder::class);
    }

    public function test_produk_seeder_lengkap_dan_fundable(): void
    {
        $this->seedChain();

        // 19 produk: 15 dipetakan ke voucher era lama + 4 produk baru.
        $this->assertSame(19, MitraProduct::count());

        foreach (MitraProduct::with('mitraProfile')->get() as $product) {
            $this->assertTrue($product->is_active, "Produk {$product->title} nonaktif.");

            $mitra = $product->mitraProfile;
            $this->assertNotNull($mitra, "Produk {$product->title} tanpa mitra.");
            $this->assertSame('verified', $mitra->status_verifikasi);
            $this->assertTrue((bool) $mitra->is_active);
        }

        // Semua voucher seeder era lama sudah ter-link ke produk.
        $this->assertSame(15, Voucher::whereNotNull('mitra_product_id')->count());
        $this->assertSame(0, Voucher::whereNull('mitra_product_id')->count());

        // Contoh pemetaan spesifik voucher → produk.
        $hemat = Voucher::where('title', 'Voucher Rp 25.000 - Sembako Hemat')->first();
        $this->assertNotNull($hemat?->mitra_product_id);
        $this->assertSame('Paket Sembako Hemat', $hemat->mitraProduct->title);

        // Dana campaign siap untuk build reward.
        $campaign = DonationCampaign::where('slug', 'dana-hijau-karbonkita')->first();
        $this->assertNotNull($campaign);
        $this->assertGreaterThanOrEqual(10000000, $campaign->availableAmount());
    }

    public function test_seeder_idempotent_tanpa_duplikasi(): void
    {
        $this->seedChain();

        $productsBefore = MitraProduct::count();
        $linkedBefore = Voucher::whereNotNull('mitra_product_id')->count();
        $campaignBefore = DonationCampaign::where('slug', 'dana-hijau-karbonkita')->first();

        $this->seed(MitraProductSeeder::class);

        $this->assertSame($productsBefore, MitraProduct::count());
        $this->assertSame($linkedBefore, Voucher::whereNotNull('mitra_product_id')->count());

        $this->assertEqualsWithDelta(
            (float) $campaignBefore->collected_amount,
            (float) $campaignBefore->fresh()->collected_amount,
            0.01,
            'Re-run seeder tidak boleh menambah dana dua kali.'
        );
    }
}
