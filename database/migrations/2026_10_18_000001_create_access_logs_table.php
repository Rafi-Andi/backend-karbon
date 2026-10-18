<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log akses API untuk halaman monitoring juri (/monitoring).
     *
     * Satu baris = satu request API (diisi middleware LogApiAccess).
     * Identitas user di-snapshot (nama + role) agar log tetap terbaca
     * walau user dihapus/diubah; user_id null = tamu (belum login).
     */
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_role', 20)->nullable();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('route', 120)->nullable();
            $table->unsignedSmallInteger('status');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
