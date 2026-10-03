<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mitra_profiles', function (Blueprint $table) {
            // Lokasi usaha terstruktur (layar Validasi Mitra UMKM).
            // Nullable agar data register lama tetap valid.
            $table->string('usaha_kelurahan')->nullable()->after('alamat_usaha');
            $table->string('usaha_kecamatan')->nullable()->after('usaha_kelurahan');
            $table->string('usaha_kota')->nullable()->after('usaha_kecamatan');
            $table->string('usaha_provinsi')->nullable()->after('usaha_kota');
            $table->string('usaha_kode_pos', 10)->nullable()->after('usaha_provinsi');

            // Foto toko 2 & 3 (FOTO 1 pakai kolom foto_toko existing).
            $table->string('foto_toko_2')->nullable()->after('foto_toko');
            $table->string('foto_toko_3')->nullable()->after('foto_toko_2');

            // Status validasi rekening untuk badge "Valid/Ready" di admin.
            // format_valid = lolos format + allowlist bank (belum bukti transfer).
            // verified = lolos bukti payout test / name validator.
            $table->enum('bank_validation_status', [
                'pending',
                'format_valid',
                'manual_review',
                'verified',
                'failed',
            ])->default('pending')->after('nama_pemilik_rekening');
            $table->timestamp('bank_validated_at')->nullable()->after('bank_validation_status');
            $table->string('bank_validation_note', 500)->nullable()->after('bank_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('mitra_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'usaha_kelurahan',
                'usaha_kecamatan',
                'usaha_kota',
                'usaha_provinsi',
                'usaha_kode_pos',
                'foto_toko_2',
                'foto_toko_3',
                'bank_validation_status',
                'bank_validated_at',
                'bank_validation_note',
            ]);
        });
    }
};
