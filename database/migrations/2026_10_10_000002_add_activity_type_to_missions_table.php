<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->string('activity_type', 20)->nullable()->after('category');
        });

        // Backfill misi mobilitas bawaan berdasarkan ikon:
        // sepeda (directions_bike, wb_sunny) vs jalan kaki (directions_walk).
        DB::table('missions')
            ->where('category', 'mobility')
            ->whereIn('icon', ['directions_bike', 'wb_sunny'])
            ->whereNull('activity_type')
            ->update(['activity_type' => 'cycling']);

        DB::table('missions')
            ->where('category', 'mobility')
            ->where('icon', 'directions_walk')
            ->whereNull('activity_type')
            ->update(['activity_type' => 'walking']);
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn('activity_type');
        });
    }
};
