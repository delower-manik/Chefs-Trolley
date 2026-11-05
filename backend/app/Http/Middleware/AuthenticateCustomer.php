<?php

namespace App\Http\Middleware;

use App\Services\Web\CustomerAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Customer-website sign-in check. Reads the bearer token from customer_sessions only — staff Sanctum tokens never match here. */
class AuthenticateCustomer
{
    public function __construct(private CustomerAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = $token ? $this->auth->resolve($token) : null;
        if (! $session || ! $session->customer) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'Please sign in.'], 401);
        }
        if (! $session->customer->is_active) {
            return response()->json(['code' => 'account_disabled', 'message' => 'This account is not active. Please contact the restaurant.'], 403);
        }
        $request->attributes->set('customer', $session->customer);
        $request->attributes->set('customer_session', $session);

        return $next($request);
    }
}
