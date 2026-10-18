<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\UserMission;
use App\Models\VoucherClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Halaman monitoring akses untuk demo/juri: GET /monitoring?token=...
 *
 * Read-only + auto-refresh (lihat view). Proteksi sederhana lewat token
 * rahasia di MONITORING_TOKEN (.env); salah/kosong → 404 (menyembunyikan
 * keberadaan halaman). Data dari middleware LogApiAccess.
 */
class MonitoringController extends Controller
{
    /** Label ramah untuk endpoint yang muncul di log (route name → aksi). */
    private const ROUTE_LABELS = [
        'auth.register-mitra' => 'Daftar akun mitra',
        'donations.campaigns' => 'Lihat campaign donasi',
        'donations.campaign-detail' => 'Detail campaign donasi',
        'user.dashboard' => 'Buka Beranda',
        'user.levels' => 'Lihat lencana level',
        'user.activities' => 'Buka aktivitas profil',
        'leaderboard.index' => 'Lihat papan peringkat',
        'saga.nodes' => 'Buka peta kuis',
        'saga.questions' => 'Buka soal kuis',
        'saga.quizzes' => 'Ambil kuis harian',
        'saga.answer' => 'Jawab kuis',
        'missions.active' => 'Lihat misi aktif',
        'missions.verify-waste' => 'Verifikasi foto sampah',
        'missions.mobility-sync' => 'Sinkron aktivitas mobilitas',
        'user.carbon-stats' => 'Lihat statistik karbon',
        'donations.store' => 'Kirim donasi',
        'donations.my' => 'Riwayat donasi saya',
        'donations.cancel' => 'Batalkan donasi',
        'vouchers.cities' => 'Lihat filter kota',
        'vouchers.index' => 'Buka marketplace',
        'vouchers.claim' => 'Tukar voucher',
        'user.my-vouchers' => 'Lihat dompet voucher',
        'merchant.dashboard' => 'Buka dashboard mitra',
        'merchant.disbursements' => 'Riwayat pencairan mitra',
        'merchant.status' => 'Ubah status toko',
        'vouchers.redeem' => 'Redeem voucher (mitra)',
        'merchant.products.index' => 'Lihat produk mitra',
        'merchant.products.store' => 'Tambah produk mitra',
        'merchant.products.update' => 'Ubah produk mitra',
        'merchant.products.photo' => 'Upload foto produk',
        'merchant.products.destroy' => 'Hapus produk mitra',
        'admin.merchants.index' => 'Admin: daftar mitra',
        'admin.merchants.pending' => 'Admin: mitra menunggu',
        'admin.merchants.show' => 'Admin: detail mitra',
        'admin.merchants.verify' => 'Admin: verifikasi mitra',
        'admin.donation-campaigns.index' => 'Admin: kelola campaign',
        'admin.donation-campaigns.store' => 'Admin: buat campaign',
        'admin.donation-campaigns.update' => 'Admin: ubah campaign',
        'admin.vouchers.store' => 'Admin: danai voucher',
        'admin.mitra-products.index' => 'Admin: lihat produk mitra',
        'admin.missions.index' => 'Admin: kelola misi',
        'admin.missions.store' => 'Admin: buat misi',
        'admin.missions.update' => 'Admin: ubah misi',
        'admin.missions.status' => 'Admin: aktif/nonaktif misi',
        'admin.vouchers.index' => 'Admin: kelola reward',
        'admin.vouchers.show' => 'Admin: detail reward',
        'admin.vouchers.update' => 'Admin: ubah tenggat reward',
        'admin.vouchers.status' => 'Admin: aktif/nonaktif reward',
        'admin.vouchers.claims' => 'Admin: lihat daftar penukar',
    ];

    public function index(Request $request): View
    {
        $token = (string) config('services.monitoring.token');

        if ($token === '' || ! hash_equals($token, (string) $request->query('token'))) {
            abort(404);
        }

        return view('monitoring', $this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        // Batas "hari ini" mengikuti WIB; disimpan UTC seperti created_at.
        $startToday = Carbon::now('Asia/Jakarta')->startOfDay()->utc();

        $logs = AccessLog::query()->orderByDesc('id')->limit(80)->get();

        $topEndpoints = AccessLog::query()
            ->selectRaw('route, path, method, count(*) as hits')
            ->where('created_at', '>=', $startToday)
            ->groupBy('route', 'path', 'method')
            ->orderByDesc('hits')
            ->limit(6)
            ->get();

        return [
            'generatedAt' => Carbon::now('Asia/Jakarta')->format('d M Y, H:i:s').' WIB',
            'logs' => $logs,
            'topEndpoints' => $topEndpoints,
            'routeLabels' => self::ROUTE_LABELS,
            'summary' => [
                'accesses_today' => AccessLog::query()
                    ->where('created_at', '>=', $startToday)
                    ->count(),
                'unique_users_today' => AccessLog::query()
                    ->where('created_at', '>=', $startToday)
                    ->whereNotNull('user_id')
                    ->distinct()
                    ->count('user_id'),
                'guests_today' => AccessLog::query()
                    ->where('created_at', '>=', $startToday)
                    ->whereNull('user_id')
                    ->count(),
                'missions_verified_today' => UserMission::query()
                    ->where('status', 'verified')
                    ->where('created_at', '>=', $startToday)
                    ->count(),
                'claims_today' => VoucherClaim::query()
                    ->where('claimed_at', '>=', $startToday)
                    ->count(),
                'redeems_today' => VoucherClaim::query()
                    ->where('status', 'used')
                    ->where('used_at', '>=', $startToday)
                    ->count(),
            ],
        ];
    }
}
