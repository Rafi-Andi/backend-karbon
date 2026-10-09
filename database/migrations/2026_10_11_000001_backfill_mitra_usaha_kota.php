<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill lokasi usaha untuk mitra era sebelum kolom usaha_*.
     * Salin dari domisili owner (users.kota/kecamatan/kelurahan).
     * Idempoten: hanya isi yang masih null/kosong. Loop PHP agar
     * jalan di MySQL maupun SQLite (test).
     */
    public function up(): void
    {
        $rows = DB::table('mitra_profiles')
            ->join('users', 'users.id', '=', 'mitra_profiles.user_id')
            ->select(
                'mitra_profiles.id',
                'mitra_profiles.usaha_kota',
                'mitra_profiles.usaha_kecamatan',
                'mitra_profiles.usaha_kelurahan',
                'users.kota',
                'users.kecamatan',
                'users.kelurahan'
            )
            ->get();

        foreach ($rows as $row) {
            $fill = [];

            if (($row->usaha_kota === null || $row->usaha_kota === '')
                && $row->kota !== null && $row->kota !== ''
            ) {
                $fill['usaha_kota'] = $row->kota;
            }

            if (($row->usaha_kecamatan === null || $row->usaha_kecamatan === '')
                && $row->kecamatan !== null && $row->kecamatan !== ''
            ) {
                $fill['usaha_kecamatan'] = $row->kecamatan;
            }

            if (($row->usaha_kelurahan === null || $row->usaha_kelurahan === '')
                && $row->kelurahan !== null && $row->kelurahan !== ''
            ) {
                $fill['usaha_kelurahan'] = $row->kelurahan;
            }

            if ($fill !== []) {
                DB::table('mitra_profiles')->where('id', $row->id)->update($fill);
            }
        }
    }

    public function down(): void
    {
        // Backfill tidak di-rollback (data sudah benar).
    }
};
