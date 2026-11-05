<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and validates the branch the request is scoped to from the
 * X-Branch-Id header, and rejects access to branches the user is not
 * assigned to. Controllers read the resolved branch from
 * $request->attributes->get('branch_id') rather than trusting client input
 * directly (architecture §11: every operational entity is branch-scoped).
 */
class EnsureBranchScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $branchId = $request->header('X-Branch-Id') ?? $user?->active_branch_id;

        if ($user && $branchId) {
            $isSuperAdmin = $user->roles()->where('name', 'Super Admin')->exists();
            $hasAccess = $isSuperAdmin || $user->branches()->where('branches.id', $branchId)->exists();

            if (! $hasAccess) {
                return response()->json([
                    'code' => 'forbidden_branch',
                    'message' => 'You do not have access to this branch.',
                ], 403);
            }
        }

        $request->attributes->set('branch_id', $branchId);

        return $next($request);
    }
}
