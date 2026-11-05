<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\SaveUserRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\Mfa;
use App\Support\AdminAccess;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Staff login accounts. Every mutation obeys the escalation rules in
 * AdminAccess: you can't grant roles or branches you don't hold, can't touch
 * people more powerful than you, can't lock yourself out, and the system can
 * never be left without an active Super Admin. Passwords are never returned
 * or written to the audit log, and any change that should end a session
 * (deactivation, password reset, deletion) revokes the user's tokens.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $search = trim((string) $request->query('q', ''));
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $paginator = AdminAccess::visibleUsers($actor)
            ->with(['roles:id,name', 'branches:id,name,code'])
            ->when($search !== '', function ($q) use ($search) {
                $like = Like::contains($search);
                $q->where(fn ($q) => $q->whereRaw(Like::clause('name'), [$like])->orWhereRaw(Like::clause('email'), [$like]));
            })
            ->when($request->query('role_id'), fn ($q, $roleId) => $q->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId)))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (User $u) => $this->serialize($u, $actor)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /** What this actor is allowed to hand out -- drives the create/edit form's pickers. */
    public function options(Request $request)
    {
        $actor = $request->user();

        return response()->json(['data' => [
            'roles' => AdminAccess::assignableRoles($actor)->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'permissions' => $r->permissions->pluck('name')->values(),
            ])->values(),
            'branches' => Branch::query()
                ->whereIn('id', AdminAccess::accessibleBranchIds($actor))
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])
                ->values(),
        ]]);
    }

    public function show(Request $request, string $user)
    {
        $target = $this->visible($request, $user);

        return response()->json(['data' => $this->serialize($target->load(['roles:id,name', 'branches:id,name,code']), $request->user())]);
    }

    public function store(SaveUserRequest $request)
    {
        $actor = $request->user();
        $roleIds = $request->validated('role_ids');
        $branchIds = $request->validated('branch_ids');

        if ($denied = $this->checkAssignable($actor, $roleIds, $branchIds)) {
            return $denied;
        }

        $activeBranchId = $request->validated('active_branch_id') ?? $branchIds[0];
        if (! in_array($activeBranchId, $branchIds, true)) {
            return $this->error('invalid_active_branch', 'The active branch must be one of the branches assigned to this user.', 422);
        }

        $user = DB::transaction(function () use ($request, $roleIds, $branchIds, $activeBranchId) {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'), // hashed by the model cast
                'active_branch_id' => $activeBranchId,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $user->roles()->sync($roleIds);
            $user->branches()->sync($branchIds);

            return $user->load(['roles:id,name', 'branches:id,name,code']);
        });

        $this->audit($request, 'user.created', $user, ['after' => $this->snapshot($user)]);

        return response()->json(['data' => $this->serialize($user, $actor)], 201);
    }

    public function update(SaveUserRequest $request, string $user)
    {
        $actor = $request->user();
        $target = $this->visible($request, $user)->load(['roles:id,name', 'branches:id,name,code']);

        if (! AdminAccess::canManageUser($actor, $target)) {
            return $this->error('cannot_manage_user', 'You do not have enough access to modify this user.', 403);
        }

        $roleIds = $request->has('role_ids') ? $request->validated('role_ids') : null;
        $branchIds = $request->has('branch_ids') ? $request->validated('branch_ids') : null;
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : $target->is_active;

        if ($denied = $this->checkAssignable($actor, $roleIds ?? [], $branchIds ?? [])) {
            return $denied;
        }

        // Nobody edits their own access: not their roles, branches, or active flag.
        if ($target->id === $actor->id) {
            $rolesChanged = $roleIds !== null && $this->differs($roleIds, $target->roles->pluck('id')->all());
            $branchesChanged = $branchIds !== null && $this->differs($branchIds, $target->branches->pluck('id')->all());
            if ($rolesChanged || $branchesChanged || ! $isActive) {
                return $this->error('cannot_modify_self', 'You cannot change your own roles, branches or active status.', 422);
            }
        }

        // The system must always keep at least one active Super Admin.
        $isSuperNow = $target->is_active && $target->roles->contains('name', AdminAccess::SUPER_ADMIN_ROLE);
        $superRoleId = Role::where('name', AdminAccess::SUPER_ADMIN_ROLE)->value('id');
        $keepsSuperRole = $roleIds === null
            ? $target->roles->contains('name', AdminAccess::SUPER_ADMIN_ROLE)
            : in_array($superRoleId, $roleIds, true);
        if ($isSuperNow && ! ($isActive && $keepsSuperRole) && AdminAccess::activeSuperAdminCount() <= 1) {
            return $this->error('last_super_admin', 'This is the only active Super Admin. Create or promote another one first.', 422);
        }

        $finalBranchIds = $branchIds ?? $target->branches->pluck('id')->all();
        $activeBranchId = $request->has('active_branch_id') ? $request->validated('active_branch_id') : $target->active_branch_id;
        if ($activeBranchId !== null && ! in_array($activeBranchId, $finalBranchIds, true)) {
            // Moving someone off their active branch: fall back to the first one they still have.
            if ($request->has('active_branch_id')) {
                return $this->error('invalid_active_branch', 'The active branch must be one of the branches assigned to this user.', 422);
            }
            $activeBranchId = $finalBranchIds[0] ?? null;
        }

        $before = $this->snapshot($target);

        DB::transaction(function () use ($request, $target, $roleIds, $branchIds, $isActive, $activeBranchId) {
            $target->fill($request->safe()->only(['name', 'email']));
            $target->is_active = $isActive;
            $target->active_branch_id = $activeBranchId;
            $target->save();

            if ($roleIds !== null) {
                $target->roles()->sync($roleIds);
            }
            if ($branchIds !== null) {
                $target->branches()->sync($branchIds);
            }
        });

        // A deactivated user must not keep working on tokens they already hold.
        if (! $isActive) {
            $target->tokens()->delete();
        }

        $target->load(['roles:id,name', 'branches:id,name,code']);
        $this->audit($request, 'user.updated', $target, ['before' => $before, 'after' => $this->snapshot($target)]);

        return response()->json(['data' => $this->serialize($target, $actor)]);
    }

    public function resetPassword(ResetUserPasswordRequest $request, string $user)
    {
        $actor = $request->user();
        $target = $this->visible($request, $user);

        if (! AdminAccess::canManageUser($actor, $target)) {
            return $this->error('cannot_manage_user', 'You do not have enough access to modify this user.', 403);
        }

        $target->forceFill(['password' => $request->validated('password'), 'password_changed_at' => now(), 'failed_login_count' => 0, 'locked_until' => null])->save(); // hashed by the cast
        $target->tokens()->delete(); // every existing session ends, including the target's refresh token

        // The password itself is never logged.
        $this->audit($request, 'user.password_reset', $target);

        return response()->json(['data' => $this->serialize($target->load(['roles:id,name', 'branches:id,name,code']), $actor)]);
    }

    /** Lost phone and no recovery codes: an administrator removes the second factor so the person can sign in and enrol again. */
    public function resetMfa(Request $request, string $user)
    {
        $actor = $request->user();
        $target = $this->visible($request, $user);

        if (! AdminAccess::canManageUser($actor, $target)) {
            return $this->error('cannot_manage_user', 'You do not have enough access to modify this user.', 403);
        }
        if ($target->id === $actor->id) {
            return $this->error('use_account_page', 'Turn off your own two-step sign-in from your Security page, where your code is checked.', 422);
        }

        app(Mfa::class)->disable($target);
        $target->tokens()->delete();
        $this->audit($request, 'user.mfa_reset', $target);

        return response()->json(['data' => $this->serialize($target->load(['roles:id,name', 'branches:id,name,code']), $actor)]);
    }

    public function destroy(Request $request, string $user)
    {
        $actor = $request->user();
        $target = $this->visible($request, $user)->load('roles:id,name');

        if (! AdminAccess::canManageUser($actor, $target)) {
            return $this->error('cannot_manage_user', 'You do not have enough access to remove this user.', 403);
        }
        if ($target->id === $actor->id) {
            return $this->error('cannot_modify_self', 'You cannot delete your own account.', 422);
        }
        if ($target->is_active && $target->roles->contains('name', AdminAccess::SUPER_ADMIN_ROLE) && AdminAccess::activeSuperAdminCount() <= 1) {
            return $this->error('last_super_admin', 'This is the only active Super Admin and cannot be removed.', 422);
        }

        $target->tokens()->delete();
        $target->delete(); // soft delete: the audit trail keeps pointing at a real (archived) row

        $this->audit($request, 'user.deleted', $target, ['before' => $this->snapshot($target)]);

        return response()->noContent();
    }

    // ------------------------------------------------------------------

    /** Loads a user the actor may see; anything else is a plain 404 so ids can't be probed. */
    private function visible(Request $request, string $id): User
    {
        return AdminAccess::visibleUsers($request->user())->findOrFail($id);
    }

    /** Returns an error response if the actor may not hand out one of these roles/branches. */
    private function checkAssignable(User $actor, array $roleIds, array $branchIds): ?JsonResponse
    {
        if ($roleIds !== []) {
            $grantable = AdminAccess::assignableRoles($actor)->pluck('id')->all();
            if (array_diff($roleIds, $grantable) !== []) {
                return $this->error('cannot_assign_role', 'You can only assign roles whose permissions you hold yourself.', 403);
            }
        }
        if ($branchIds !== []) {
            $accessible = AdminAccess::accessibleBranchIds($actor)->all();
            if (array_diff($branchIds, $accessible) !== []) {
                return $this->error('cannot_assign_branch', 'You can only assign branches you have access to yourself.', 403);
            }
        }

        return null;
    }

    private function differs(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a !== $b;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'branches' => $user->branches->pluck('code')->sort()->values()->all(),
        ];
    }

    private function audit(Request $request, string $action, User $subject, ?array $changes = null): void
    {
        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $action,
            subjectType: User::class,
            subjectId: $subject->id,
            changes: $changes,
            ipAddress: $request->ip(),
        );
    }

    private function serialize(User $user, User $actor): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'isActive' => (bool) $user->is_active,
            'activeBranchId' => $user->active_branch_id,
            'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
            'branches' => $user->branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values(),
            'isSelf' => $user->id === $actor->id,
            'createdAt' => $user->created_at,
        ];
    }
}
