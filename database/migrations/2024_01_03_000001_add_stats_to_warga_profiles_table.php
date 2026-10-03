<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warga_profiles', function (Blueprint $table) {
            $table->decimal('total_distance_km', 8, 2)->default(0)->after('last_mission_at');
            $table->decimal('total_waste_kg', 8, 2)->default(0)->after('total_distance_km');
            $table->decimal('total_carbon_saved_kg', 8, 2)->default(0)->after('total_waste_kg');
        });
    }

    public function down(): void
    {
        Schema::table('warga_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'total_distance_km',
                'total_waste_kg',
                'total_carbon_saved_kg',
            ]);
        });
    }
};
