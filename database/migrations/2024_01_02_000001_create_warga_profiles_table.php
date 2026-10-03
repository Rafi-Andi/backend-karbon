<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warga_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('level')->default('Earth Newbie');
            $table->integer('xp')->default(0);
            $table->integer('eco_points')->default(0);
            $table->integer('streak_days')->default(0);
            $table->timestamp('last_mission_at')->nullable();
            $table->timestamps();

            $table->index('eco_points');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warga_profiles');
    }
};
