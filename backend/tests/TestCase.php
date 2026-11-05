<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    /**
     * Creates a branch, a user with a role carrying exactly the given
     * permissions, and authenticates the test client as that user
     * (Sanctum). Central helper so every Feature test exercises the same
     * real RBAC path instead of stubbing authorization away.
     */
    protected function actingAsUserWithPermissions(array $permissions, ?Branch $branch = null): User
    {
        $branch ??= Branch::create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'active_branch_id' => $branch->id,
        ]);
        $user->branches()->attach($branch->id);

        $role = Role::create(['name' => 'Test Role '.uniqid()]);
        $permissionModels = collect($permissions)->map(
            fn ($name) => Permission::firstOrCreate(['name' => $name])
        );
        $role->permissions()->sync($permissionModels->pluck('id'));
        $user->roles()->attach($role->id);

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /**
     * JSON has no int/float distinction for whole numbers: json_encode(500.0)
     * is `500`, which decodes to int 500. assertJsonPath() compares strictly,
     * so expectations built from float arithmetic go through here to become
     * what the API really sends (int when whole, float otherwise).
     */
    protected function num(int|float $value): int|float
    {
        return is_float($value) && floor($value) === $value ? (int) $value : $value;
    }

    protected function createBranch(array $overrides = []): Branch
    {
        return Branch::create(array_merge([
            'name' => 'Branch '.uniqid(),
            'code' => 'BR-'.uniqid(),
        ], $overrides));
    }
}
