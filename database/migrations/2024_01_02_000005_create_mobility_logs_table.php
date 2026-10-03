<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_mission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('start_point');
            $table->json('end_point');
            $table->json('route_coordinates')->nullable();
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->decimal('co2_saved_grams', 10, 2)->default(0);
            $table->integer('duration_minutes')->default(0);
            $table->enum('transport_mode', ['walking', 'cycling', 'public_transport'])->default('walking');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_logs');
    }
};
