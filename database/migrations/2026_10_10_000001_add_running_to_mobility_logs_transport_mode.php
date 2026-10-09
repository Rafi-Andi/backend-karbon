<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODES_WITH_RUNNING = ['walking', 'running', 'cycling', 'public_transport'];

    private const MODES_ORIGINAL = ['walking', 'cycling', 'public_transport'];

    public function up(): void
    {
        // doctrine/dbal tidak terinstal sehingga ->change() tidak bisa dipakai.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE mobility_logs MODIFY transport_mode ENUM('walking', 'running', 'cycling', 'public_transport') NOT NULL DEFAULT 'walking'"
            );
        } else {
            // SQLite membuat CHECK constraint dari enum saat tabel dibuat,
            // jadi satu-satunya cara melebarkan nilai adalah rebuild tabel.
            $this->rebuildMobilityLogsTable(self::MODES_WITH_RUNNING);
        }
    }

    public function down(): void
    {
        DB::table('mobility_logs')->where('transport_mode', 'running')->update(['transport_mode' => 'walking']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE mobility_logs MODIFY transport_mode ENUM('walking', 'cycling', 'public_transport') NOT NULL DEFAULT 'walking'"
            );
        } else {
            $this->rebuildMobilityLogsTable(self::MODES_ORIGINAL);
        }
    }

    /**
     * @param  array<int, string>  $modes
     */
    private function rebuildMobilityLogsTable(array $modes): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            Schema::create('mobility_logs_new', function (Blueprint $table) use ($modes) {
                $table->id();
                $table->foreignId('user_mission_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->json('start_point');
                $table->json('end_point');
                $table->json('route_coordinates')->nullable();
                $table->decimal('distance_km', 8, 2)->default(0);
                $table->decimal('co2_saved_grams', 10, 2)->default(0);
                $table->integer('duration_minutes')->default(0);
                $table->enum('transport_mode', $modes)->default('walking');
                $table->timestamps();

                $table->index('user_id');
            });

            DB::table('mobility_logs_new')->insertUsing(
                ['id', 'user_mission_id', 'user_id', 'start_point', 'end_point',
                    'route_coordinates', 'distance_km', 'co2_saved_grams',
                    'duration_minutes', 'transport_mode', 'created_at', 'updated_at'],
                DB::table('mobility_logs')->select(
                    ['id', 'user_mission_id', 'user_id', 'start_point', 'end_point',
                        'route_coordinates', 'distance_km', 'co2_saved_grams',
                        'duration_minutes', 'transport_mode', 'created_at', 'updated_at']
                )
            );

            Schema::drop('mobility_logs');
            Schema::rename('mobility_logs_new', 'mobility_logs');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
