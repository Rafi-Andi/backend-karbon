<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leaderboard kini difilter per wilayah penuh (kota+kecamatan+kelurahan+rw+rt),
        // bukan hanya rt/rw global. Index ini mempercepat scope=rt dan scope=rw.
        //
        // PENTING: kolom wilayah di `users` bertipe VARCHAR(255). Index penuh
        // 5 kolom = 5 x 255 x 4 byte (utf8mb4) = 5100 byte > batas key InnoDB
        // 3072 byte (MySQL/MariaDB error 1071, muncul di deploy VPS). Solusi:
        // prefix index 100 char/kolom (2000 byte) untuk MySQL/MariaDB.
        // Driver lain (SQLite pada test) tetap index biasa tanpa prefix karena
        // tidak punya batas panjang key seperti InnoDB.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `users` ADD INDEX `users_wilayah_rt_index` (`kota`(100), `kecamatan`(100), `kelurahan`(100), `rw`(100), `rt`(100))');
            DB::statement('ALTER TABLE `users` ADD INDEX `users_wilayah_rw_index` (`kota`(100), `kecamatan`(100), `kelurahan`(100), `rw`(100))');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->index(['kota', 'kecamatan', 'kelurahan', 'rw', 'rt'], 'users_wilayah_rt_index');
                $table->index(['kota', 'kecamatan', 'kelurahan', 'rw'], 'users_wilayah_rw_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_wilayah_rt_index');
            $table->dropIndex('users_wilayah_rw_index');
        });
    }
};
