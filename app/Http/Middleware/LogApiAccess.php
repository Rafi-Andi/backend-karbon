<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Catat setiap request API ke tabel `access_logs` untuk halaman
 * /monitoring (kebutuhan demo/juri: "siapa mengakses apa").
 *
 * - Dipasang di grup `api` (lihat bootstrap/app.php). Request web
 *   seperti /monitoring sendiri TIDAK ikut tercatat agar log bersih.
 * - Ditulis post-response: pipeline sudah melewati auth:sanctum,
 *   jadi nama + role user terbaca; tamu (belum login) user_id null.
 * - Logging best-effort: kegagalan tulis tidak boleh memutus API.
 * - Sekali per ~200 request, log yang lebih tua dari RETENTION_DAYS
 *   dihapus (tanpa scheduler tambahan).
 */
class LogApiAccess
{
    private const RETENTION_DAYS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $response = null;

        try {
            $response = $next($request);
        } finally {
            $this->write($request, $response?->getStatusCode() ?? 500);
        }

        return $response;
    }

    private function write(Request $request, int $status): void
    {
        // Preflight CORS & request tanpa route (mis. 404 dini) tak berguna.
        if ($request->isMethod('OPTIONS')) {
            return;
        }

        try {
            $user = Auth::user() ?? $request->user();

            AccessLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'user_role' => $user?->role,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
                'status' => $status,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);

            if (random_int(1, 200) === 1) {
                AccessLog::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
            }
        } catch (Throwable) {
            // Diamkan: monitoring tidak boleh mengganggu lalu lintas API.
        }
    }
}
