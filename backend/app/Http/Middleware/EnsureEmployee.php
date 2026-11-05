<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The signed-in user must be linked to an ACTIVE employee record; that record (not a client header) decides the branch for staff-app actions. */
class EnsureEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user() ? Employee::where('user_id', $request->user()->id)->where('is_active', true)->first() : null;
        if (! $employee) {
            return response()->json(['code' => 'not_an_employee', 'message' => 'This account is not linked to an active employee profile. Ask your manager.'], 403);
        }
        $request->attributes->set('employee', $employee);

        return $next($request);
    }
}
