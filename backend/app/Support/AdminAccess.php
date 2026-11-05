<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The privilege-escalation rules for user/role/branch administration, kept in
 * one place so every endpoint applies exactly the same ones.
 *
 * The principle: holding `users.manage` lets you administer people, but never
 * hands out more power than you already have. Only a Super Admin is exempt.
 *
 *  - You can only grant a role whose permissions you hold yourself, and never
 *    the Super Admin role.
 *  - You can only put someone in branches you can access yourself.
 *  - You can only see and manage users who share a branch with you, and whose
 *    roles you could have granted.
 */
class AdminAccess
{
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    public static function isSuperAdmin(User $user): bool
    {
        return $user->roles()->where('name', self::SUPER_ADMIN_ROLE)->exists();
    }

    /** Permission keys the actor holds (empty for nobody; irrelevant for a Super Admin, who bypasses). */
    public static function permissionsOf(User $actor): array
    {
        return $actor->permissionKeys();
    }

    /** Can this actor hand out this role? */
    public static function canGrantRole(User $actor, Role $role): bool
    {
        if (self::isSuperAdmin($actor)) {
            return true;
        }
        if ($role->name === self::SUPER_ADMIN_ROLE) {
            return false;
        }

        return self::holdsAll($actor, $role);
    }

    /** True when every permission on the role is one the actor holds. */
    public static function holdsAll(User $actor, Role $role): bool
    {
        $held = self::permissionsOf($actor);

        return $role->permissions->pluck('name')->every(fn ($p) => in_array($p, $held, true));
    }

    public static function assignableRoles(User $actor): Collection
    {
        return Role::query()->with('permissions:id,name')->orderBy('name')->get()
            ->filter(fn (Role $r) => self::canGrantRole($actor, $r))
            ->values();
    }

    /** Branch ids the actor may assign to others. */
    public static function accessibleBranchIds(User $actor): Collection
    {
        if (self::isSuperAdmin($actor)) {
            return Branch::query()->pluck('id');
        }

        return $actor->branches()->pluck('branches.id');
    }

    /** Users the actor is allowed to see at all. */
    public static function visibleUsers(User $actor)
    {
        $query = User::query();

        if (self::isSuperAdmin($actor)) {
            return $query;
        }

        $branchIds = self::accessibleBranchIds($actor);

        return $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds));
    }

    /**
     * Can the actor modify this user (edit, deactivate, reset password,
     * delete)? Beyond the visibility rule, a non-super-admin may not touch
     * anyone holding a role they could not have granted -- otherwise a
     * limited admin could reset a more powerful user's password and sign in
     * as them.
     */
    public static function canManageUser(User $actor, User $target): bool
    {
        if (self::isSuperAdmin($actor)) {
            return true;
        }

        if (! self::visibleUsers($actor)->whereKey($target->id)->exists()) {
            return false;
        }

        return $target->roles()->with('permissions:id,name')->get()
            ->every(fn (Role $r) => self::canGrantRole($actor, $r));
    }

    /** Active, non-deleted Super Admins -- the people who can still recover the system. */
    public static function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', self::SUPER_ADMIN_ROLE))
            ->count();
    }
}
