<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Produksi selalu di belakang reverse proxy TLS (Caddy mode tanpa
        // panel / Nginx CloudPanel); PHP-FPM sendiri hanya melihat HTTP.
        // Paksa semua URL yang digenerate (asset Vite, redirect, signed URL)
        // memakai skema https agar tidak kena blokir mixed-content browser.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
