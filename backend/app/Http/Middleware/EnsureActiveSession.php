<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs right after `auth:sanctum` and closes two gaps Sanctum leaves open on
 * its own:
 *
 *  1. A deactivated account. Sanctum only checks that the token exists and has
 *     not expired, so without this a user an admin just switched off would
 *     keep working until their access token ran out.
 *  2. A refresh token used as a bearer token. Sanctum does not enforce token
 *     abilities unless asked to, and the 30-day refresh token (ability
 *     `refresh` only) would otherwise authenticate every API route exactly
 *     like the 30-minute access token (ability `*`). Only an access token may
 *     call the API; a refresh token may only be exchanged at /auth/refresh.
 */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            return response()->json([
                'code' => 'account_disabled',
                'message' => 'This account has been disabled.',
            ], 401);
        }

        // Null for session/transient authentication, a PersonalAccessToken for bearer tokens.
        $token = $user?->currentAccessToken();
        if ($token && ! $token->can('*')) {
            return response()->json([
                'code' => 'invalid_token_type',
                'message' => 'This token cannot be used to access the API.',
            ], 401);
        }

        return $next($request);
    }
}
