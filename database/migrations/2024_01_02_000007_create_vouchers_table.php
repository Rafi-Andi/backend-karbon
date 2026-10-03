<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mitra_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('image_url')->nullable();
            $table->integer('points_cost');
            $table->decimal('rupiah_value', 15, 2);
            $table->integer('stock')->default(0);
            $table->integer('claimed_count')->default(0);
            $table->date('expired_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('mitra_profile_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
