<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Ensure the authenticated user has one of the given roles.
     *
     * Usage: ->middleware('role:mitra') or ->middleware('role:admin')
     *
     * NOTE: DB enum is ['warga','mitra','admin'] while docs/ERD call it
     * 'super_admin'. Both strings are treated as the same admin privilege
     * so routes may use either 'admin' or 'super_admin'.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Support both 'role:mitra,super_admin' (single arg with comma)
        // and 'role:mitra' / multiple args.
        $allowed = [];
        foreach ($roles as $role) {
            foreach (explode(',', $role) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $allowed[] = $part;
                }
            }
        }

        if (! in_array($user->role, $allowed, true)) {
            // 'admin' (DB enum) and 'super_admin' (docs/ERD) are aliases.
            $normalizedUser = $user->role === 'super_admin' ? 'admin' : $user->role;
            $normalizedAllowed = array_map(
                fn ($r) => $r === 'super_admin' ? 'admin' : $r,
                $allowed
            );

            if (! in_array($normalizedUser, $normalizedAllowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden. This action requires role: '.implode('|', $allowed).'.',
                ], 403);
            }
        }

        return $next($request);
    }
}
