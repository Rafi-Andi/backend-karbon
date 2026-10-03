<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kategori voucher marketplace (dipakai filter ?category= di VoucherController@index).
     *
     * @var array<int, string>
     */
    private const CATEGORIES = ['kuliner', 'sembako', 'fashion', 'jasa', 'donasi', 'transportasi'];

    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // string (bukan enum) agar kompatibel SQLite saat testing.
            $table->string('category', 30)->default('kuliner')->after('description');
            $table->index('category');
        });

        // Backfill idempoten: voucher lama tanpa kategori yang jelas.
        // Donasi Mangrove -> donasi, sisanya tetap default kuliner.
        DB::table('vouchers')
            ->where('title', 'like', '%Mangrove%')
            ->update(['category' => 'donasi']);
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
