<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveRoleRequest;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Role and permission management. A role is just a named bundle of the fixed
 * permission catalogue (seeded, not creatable through the API). The sharpest
 * edge in the whole admin area is here, so the rules are strict:
 *  - the Super Admin role is immutable and undeletable;
 *  - a non-super-admin can only put permissions they hold into a role, and
 *    can't edit or delete a role that contains permissions they don't hold;
 *  - a role still assigned to anyone can't be deleted.
 */
class RoleController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();

        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $roles->map(fn (Role $r) => $this->serialize($r, $actor))->values()]);
    }

    /** The fixed permission catalogue, flagged with which ones this actor may hand out. */
    public function permissions(Request $request)
    {
        $actor = $request->user();
        $isSuper = AdminAccess::isSuperAdmin($actor);
        $held = AdminAccess::permissionsOf($actor);

        return response()->json(['data' => Permission::query()->orderBy('name')->get(['name', 'description'])
            ->map(fn ($p) => [
                'name' => $p->name,
                'description' => $p->description,
                'grantable' => $isSuper || in_array($p->name, $held, true),
            ])->values()]);
    }

    public function store(SaveRoleRequest $request)
    {
        $actor = $request->user();
        $permissions = $request->validated('permissions');

        if ($denied = $this->checkGrantable($actor, $permissions)) {
            return $denied;
        }

        $role = DB::transaction(function () use ($request, $permissions) {
            $role = Role::create([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
            ]);
            $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

            return $role->load('permissions:id,name');
        });

        $this->audit($request, 'role.created', $role, ['after' => $this->snapshot($role)]);

        return response()->json(['data' => $this->serialize($role->loadCount('users'), $actor)], 201);
    }

    public function update(SaveRoleRequest $request, string $role)
    {
        $actor = $request->user();
        $target = Role::with('permissions:id,name')->findOrFail($role);

        if ($target->name === AdminAccess::SUPER_ADMIN_ROLE) {
            return $this->error('system_role', 'The Super Admin role is built in and cannot be changed.', 422);
        }
        if (! AdminAccess::isSuperAdmin($actor) && ! AdminAccess::holdsAll($actor, $target)) {
            return $this->error('role_exceeds_your_access', 'This role contains permissions you do not hold, so you cannot edit it.', 403);
        }

        $permissions = $request->has('permissions') ? $request->validated('permissions') : null;
        if ($permissions !== null && ($denied = $this->checkGrantable($actor, $permissions))) {
            return $denied;
        }

        $before = $this->snapshot($target);

        DB::transaction(function () use ($request, $target, $permissions) {
            $target->fill($request->safe()->only(['name', 'description']))->save();
            if ($permissions !== null) {
                $target->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
            }
        });

        $target->load('permissions:id,name');
        $after = $this->snapshot($target);
        $this->audit($request, 'role.updated', $target, [
            'before' => $before,
            'after' => $after,
            'permissions_added' => array_values(array_diff($after['permissions'], $before['permissions'])),
            'permissions_removed' => array_values(array_diff($before['permissions'], $after['permissions'])),
        ]);

        return response()->json(['data' => $this->serialize($target->loadCount('users'), $actor)]);
    }

    public function destroy(Request $request, string $role)
    {
        $actor = $request->user();
        $target = Role::with('permissions:id,name')->withCount('users')->findOrFail($role);

        if ($target->name === AdminAccess::SUPER_ADMIN_ROLE) {
            return $this->error('system_role', 'The Super Admin role is built in and cannot be removed.', 422);
        }
        if (! AdminAccess::isSuperAdmin($actor) && ! AdminAccess::holdsAll($actor, $target)) {
            return $this->error('role_exceeds_your_access', 'This role contains permissions you do not hold, so you cannot remove it.', 403);
        }
        if ($target->users_count > 0) {
            return $this->error('role_in_use', "This role is still assigned to {$target->users_count} user(s). Reassign them first.", 422);
        }

        $snapshot = $this->snapshot($target);
        $target->delete();

        $this->audit($request, 'role.deleted', $target, ['before' => $snapshot]);

        return response()->noContent();
    }

    // ------------------------------------------------------------------

    private function checkGrantable($actor, array $permissions): ?JsonResponse
    {
        if (AdminAccess::isSuperAdmin($actor)) {
            return null;
        }
        if (array_diff($permissions, AdminAccess::permissionsOf($actor)) !== []) {
            return $this->error('cannot_grant_permission', 'You can only include permissions you hold yourself.', 403);
        }

        return null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }

    private function audit(Request $request, string $action, Role $subject, ?array $changes = null): void
    {
        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $action,
            subjectType: Role::class,
            subjectId: $subject->id,
            changes: $changes,
            ipAddress: $request->ip(),
        );
    }

    private function serialize(Role $role, $actor): array
    {
        $isSystem = $role->name === AdminAccess::SUPER_ADMIN_ROLE;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
            'userCount' => (int) ($role->users_count ?? 0),
            'isSystem' => $isSystem,
            // Can this actor edit/delete it? (The API enforces the same rule; this just lets the UI disable the buttons.)
            'editable' => ! $isSystem && (AdminAccess::isSuperAdmin($actor) || AdminAccess::holdsAll($actor, $role)),
        ];
    }
}
