<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SocietyTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Placeholder for society-scoped token validation.
        // Currently behaves as auth:sanctum passthrough.
        // Extend with scope/ability checks when needed.
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
