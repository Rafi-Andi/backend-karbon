<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDonationController;
use App\Http\Controllers\AdminMissionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarbonStatsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\MitraRegisterController;
use App\Http\Controllers\SagaController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register-mitra', [MitraRegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('auth.register-mitra');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
});

// Donasi: katalog campaign publik (CSR/komunitas bisa lihat tanpa login)
Route::get('/donation-campaigns', [DonationController::class, 'campaigns'])
    ->name('donations.campaigns');
Route::get('/donation-campaigns/{slug}', [DonationController::class, 'campaignDetail'])
    ->name('donations.campaign-detail');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/user', function () {
        return response()->json([
            'success' => true,
            'data' => auth()->user(),
        ]);
    });

    // Dashboard
    Route::get('/user/dashboard', [DashboardController::class, 'index'])
        ->name('user.dashboard');

    // Level tiers (lencana 3 tier untuk Profil)
    Route::get('/user/levels', [LevelController::class, 'index'])
        ->name('user.levels');

    // Aktivitas terbaru untuk Profil
    Route::get('/user/activities', [ActivityController::class, 'index'])
        ->name('user.activities');

    // Leaderboard
    Route::get('/leaderboard', [LeaderboardController::class, 'index'])
        ->name('leaderboard.index');

    // Saga Map (Quiz) — throttle brute-force on answer
    Route::get('/saga/nodes', [SagaController::class, 'nodes'])
        ->name('saga.nodes');
    Route::get('/saga/nodes/{id}/questions', [SagaController::class, 'questions'])
        ->whereNumber('id')
        ->name('saga.questions');
    Route::get('/saga/quizzes', [SagaController::class, 'index'])
        ->name('saga.quizzes');
    Route::post('/saga/answer', [SagaController::class, 'answer'])
        ->middleware('throttle:10,1')
        ->name('saga.answer');

    // Activity Missions (mobility & waste)
    Route::get('/missions/active', [MissionController::class, 'index'])
        ->name('missions.active');
    Route::post('/missions/verify-waste', [MissionController::class, 'verifyWaste'])
        ->middleware('throttle:10,1')
        ->name('missions.verify-waste');
    Route::post('/missions/mobility-sync', [MissionController::class, 'mobilitySync'])
        ->name('missions.mobility-sync');

    // Carbon Stats
    Route::get('/user/carbon-stats', [CarbonStatsController::class, 'index'])
        ->name('user.carbon-stats');

    // Donasi (donatur wajib login)
    Route::post('/donations', [DonationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('donations.store');
    Route::get('/user/my-donations', [DonationController::class, 'myDonations'])
        ->name('donations.my');
    Route::post('/donations/{id}/cancel', [DonationController::class, 'cancel'])
        ->whereNumber('id')
        ->name('donations.cancel');

    // Marketplace & Vouchers
    Route::get('/vouchers', [VoucherController::class, 'index'])
        ->name('vouchers.index');
    Route::post('/vouchers/claim', [VoucherController::class, 'claim'])
        ->name('vouchers.claim');
    Route::get('/user/my-vouchers', [VoucherController::class, 'myVouchers'])
        ->name('user.my-vouchers');

    // Merchant (Mitra UMKM) — role:mitra
    Route::middleware('role:mitra')->group(function () {
        Route::get('/merchant/dashboard', [MerchantController::class, 'dashboard'])
            ->name('merchant.dashboard');
        Route::get('/merchant/disbursements', [MerchantController::class, 'disbursements'])
            ->name('merchant.disbursements');
        Route::patch('/merchant/status', [MerchantController::class, 'updateStatus'])
            ->name('merchant.status');
        Route::post('/vouchers/redeem', [MerchantController::class, 'redeem'])
            ->middleware('throttle:30,1')
            ->name('vouchers.redeem');
    });

    // Super Admin — role:admin (alias super_admin, lihat EnsureRole)
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/merchants', [AdminController::class, 'index'])
            ->name('admin.merchants.index');
        Route::get('/admin/merchants/pending', [AdminController::class, 'pending'])
            ->name('admin.merchants.pending');
        Route::get('/admin/merchants/{id}', [AdminController::class, 'show'])
            ->whereNumber('id')
            ->name('admin.merchants.show');
        Route::post('/admin/merchants/{id}/verify', [AdminController::class, 'verify'])
            ->name('admin.merchants.verify');

        // Donasi & pendanaan voucher (CSR/komunitas)
        Route::get('/admin/donation-campaigns', [AdminDonationController::class, 'index'])
            ->name('admin.donation-campaigns.index');
        Route::post('/admin/donation-campaigns', [AdminDonationController::class, 'store'])
            ->name('admin.donation-campaigns.store');
        Route::patch('/admin/donation-campaigns/{id}', [AdminDonationController::class, 'update'])
            ->whereNumber('id')
            ->name('admin.donation-campaigns.update');
        Route::post('/admin/vouchers', [AdminDonationController::class, 'storeVoucher'])
            ->name('admin.vouchers.store');

        // Kelola misi mobility & waste (quiz/donation via seeder).
        Route::get('/admin/missions', [AdminMissionController::class, 'index'])
            ->name('admin.missions.index');
        Route::post('/admin/missions', [AdminMissionController::class, 'store'])
            ->name('admin.missions.store');
        Route::patch('/admin/missions/{id}', [AdminMissionController::class, 'update'])
            ->whereNumber('id')
            ->name('admin.missions.update');
        Route::patch('/admin/missions/{id}/status', [AdminMissionController::class, 'setStatus'])
            ->whereNumber('id')
            ->name('admin.missions.status');
    });
});

Route::post('/webhooks/xendit/payout', [WebhookController::class, 'payout']);
Route::post('/webhooks/xendit/invoice', [WebhookController::class, 'invoice']);
