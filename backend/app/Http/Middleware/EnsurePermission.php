<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side authorization enforcement. UI hiding (the `Can` component on
 * the frontend) is a UX convenience only -- this middleware is the actual
 * security boundary (Non-Negotiable Rule #9).
 *
 * Usage: Route::middleware('permission:orders.cancel')->post(...);
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'code' => 'unauthenticated',
                'message' => 'You must be signed in to do that.',
            ], 401);
        }

        if (! $user->hasPermissionKey($permission)) {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
