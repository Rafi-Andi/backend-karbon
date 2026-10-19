<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\LogApiAccess;
use App\Http\Middleware\SocietyTokenMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        // Aplikasi ini API-only: tidak ada route `login`. Redirect default
        // Laravel memanggil route('login') dari dalam middleware Authenticate,
        // sehingga request tanpa header Accept (curl, browser) gagal 500
        // sebelum sempat jadi 401. Null = langsung lempar AuthenticationException,
        // yang oleh handler dirender JSON 401 untuk rute api/*.
        $middleware->redirectGuestsTo(fn (): ?string => null);

        $middleware->alias([
            'auth.society' => SocietyTokenMiddleware::class,
            'role' => EnsureRole::class,
        ]);

        // Catat semua request API ke access_logs untuk halaman /monitoring
        // (demo/juri). Hanya grup api — halaman web tidak ikut tercatat.
        $middleware->appendToGroup('api', LogApiAccess::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Rute API selalu balas JSON walau klien tidak mengirim header Accept.
        // Tanpa ini, request tanpa Accept (curl, browser, Postman default)
        // memicu redirect ke route `login` yang tidak ada di aplikasi
        // API-only ini -> HTTP 500, bukan 401 "Unauthenticated.".
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
