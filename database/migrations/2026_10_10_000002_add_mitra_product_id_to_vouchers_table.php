<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak snapshot: voucher batch dibuat dari produk mana.
     * Nullable agar voucher era lama (input manual) tetap valid.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('mitra_product_id')
                ->nullable()
                ->after('mitra_profile_id')
                ->constrained('mitra_products')
                ->nullOnDelete();
            $table->index('mitra_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['mitra_product_id']);
            $table->dropForeign(['mitra_product_id']);
            $table->dropColumn('mitra_product_id');
        });
    }
};
