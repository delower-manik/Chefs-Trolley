<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Object-level branch authorization for route-model-bound records.
 *
 * EnsureBranchScope checks the *active branch* header, but a request such as
 * PUT /tables/{id} binds a record by id alone: without this check anyone with
 * `tables.manage` in branch A could edit or delete branch B's table just by
 * knowing its id (IDOR). Every bound model that carries a `branch_id` must
 * belong to a branch the user can access; otherwise we answer 404 (not 403)
 * so ids of other branches are not even confirmed to exist.
 *
 * Records with a null branch_id are chain-wide and remain subject only to the
 * route's permission check.
 *
 * Must run after SubstituteBindings (see bootstrap/app.php priority list).
 */
class EnsureRouteModelsInBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if (! $user || ! $route) {
            return $next($request);
        }

        $isSuper = AdminAccess::isSuperAdmin($user);
        if (! $isSuper) {
            $accessible = AdminAccess::accessibleBranchIds($user);

            foreach ($route->parameters() as $parameter) {
                if (! $parameter instanceof Model || ! array_key_exists('branch_id', $parameter->getAttributes())) {
                    continue;
                }

                $branchId = $parameter->getAttribute('branch_id');

                // Chain-wide records (null branch) stay reachable to anyone holding
                // the route's permission; only records of OTHER branches are hidden.
                if ($branchId === null) {
                    continue;
                }

                if (! $accessible->contains($branchId)) {
                    return response()->json(['code' => 'not_found', 'message' => 'The requested resource was not found.'], 404);
                }
            }
        }

        return $next($request);
    }
}
