<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master produk milik mitra. Harga fix diinput mitra.
     * Voucher batch pendanaan adalah snapshot dari produk ini
     * (tidak live-follow agar payout & audit stabil).
     */
    public function up(): void
    {
        Schema::create('mitra_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mitra_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            // string (bukan enum) agar kompatibel SQLite saat testing.
            $table->string('category', 30)->default('kuliner');
            $table->string('image_url')->nullable();
            $table->decimal('rupiah_value', 15, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('mitra_profile_id');
            $table->index('is_active');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mitra_products');
    }
};
