<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leaderboard kini difilter per wilayah penuh (kota+kecamatan+kelurahan+rw+rt),
        // bukan hanya rt/rw global. Index ini mempercepat scope=rt dan scope=rw.
        Schema::table('users', function (Blueprint $table) {
            $table->index(['kota', 'kecamatan', 'kelurahan', 'rw', 'rt'], 'users_wilayah_rt_index');
            $table->index(['kota', 'kecamatan', 'kelurahan', 'rw'], 'users_wilayah_rw_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_wilayah_rt_index');
            $table->dropIndex('users_wilayah_rw_index');
        });
    }
};
