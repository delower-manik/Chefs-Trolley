<?php

namespace App\Http\Middleware;

use App\Services\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `feature:gift_cards` — refuses the route when that module has been switched off in Settings. */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! app(AppSettings::class)->enabled($feature)) {
            return response()->json(['code' => 'feature_disabled', 'message' => 'This feature has been turned off in Settings.'], 403);
        }

        return $next($request);
    }
}
