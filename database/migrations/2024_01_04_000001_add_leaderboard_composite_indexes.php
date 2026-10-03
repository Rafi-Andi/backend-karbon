<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users: leaderboard filters by rt/rw (see LeaderboardController.php:87-90).
        // Composite indexes accelerate scope=rt (rt+rw) and scope=rw queries.
        Schema::table('users', function (Blueprint $table) {
            $table->index(['rw', 'rt'], 'users_rw_rt_index');
            $table->index('rw', 'users_rw_index');
            $table->index('rt', 'users_rt_index');
        });

        // Warga profiles: eco_points already indexed (2024_01_02_000001).
        // Composite (rt, rw, eco_points DESC) from PROJECT_CONTEXT.md:69 cannot
        // span tables (rt/rw live in users). Keep single eco_points index and
        // add user_missions composite for the heavy join/filter in leaderboard.
        Schema::table('user_missions', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'user_missions_status_created_at_index');
            $table->index(['user_id', 'status'], 'user_missions_user_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_rw_rt_index');
            $table->dropIndex('users_rw_index');
            $table->dropIndex('users_rt_index');
        });

        Schema::table('user_missions', function (Blueprint $table) {
            $table->dropIndex('user_missions_status_created_at_index');
            $table->dropIndex('user_missions_user_status_index');
        });
    }
};
