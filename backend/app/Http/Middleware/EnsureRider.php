<?php

namespace App\Http\Middleware;

use App\Models\Rider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The signed-in staff user must be linked to an ACTIVE rider record; that record (not any client header) decides the branch. */
class EnsureRider
{
    public function handle(Request $request, Closure $next): Response
    {
        $rider = $request->user() ? Rider::where('user_id', $request->user()->id)->where('is_active', true)->first() : null;
        if (! $rider) {
            return response()->json(['code' => 'not_a_rider', 'message' => 'This account is not linked to an active rider profile. Ask your manager.'], 403);
        }
        $request->attributes->set('rider', $rider);

        return $next($request);
    }
}
