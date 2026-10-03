<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mitra_profiles', function (Blueprint $table) {
            $table->string('nama_usaha')->nullable()->change();
            $table->string('jenis_usaha')->nullable()->change();
            $table->text('alamat_usaha')->nullable()->change();
            $table->string('nama_bank')->nullable()->change();
            $table->string('nomor_rekening')->nullable()->change();
            $table->string('nama_pemilik_rekening')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mitra_profiles', function (Blueprint $table) {
            $table->string('nama_usaha')->nullable(false)->change();
            $table->string('jenis_usaha')->nullable(false)->change();
            $table->text('alamat_usaha')->nullable(false)->change();
            $table->string('nama_bank')->nullable(false)->change();
            $table->string('nomor_rekening')->nullable(false)->change();
            $table->string('nama_pemilik_rekening')->nullable(false)->change();
        });
    }
};
