<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SocietyTokenMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Produksi selalu di belakang reverse proxy (Caddy/CloudPanel) yang
        // mengirim X-Forwarded-* lewat nginx container. Percayai header itu
        // agar skema request (https) dan IP client asli terbaca — tanpa ini
        // rate limit login ikut ter-bagi ke semua user (1 IP container).
        // Aman karena port app (9000) tidak pernah dipublish ke host.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'auth.society' => SocietyTokenMiddleware::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
