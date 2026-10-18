<?php

use App\Http\Controllers\MonitoringController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Monitoring akses untuk juri/demo — proteksi token rahasia
// (MONITORING_TOKEN di .env; salah/kosong = 404).
Route::get('/monitoring', [MonitoringController::class, 'index'])
    ->name('monitoring.index');
