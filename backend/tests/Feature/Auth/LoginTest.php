<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_user_can_log_in_with_correct_credentials(): void
    {
        $branch = $this->createBranch();
        $user = User::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@chefstrolley.test',
            'password' => Hash::make('correct-password'),
            'active_branch_id' => $branch->id,
        ]);
        $user->branches()->attach($branch->id);
        $role = Role::create(['name' => 'Super Admin']);
        $user->roles()->attach($role->id);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@chefstrolley.test',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', 'ada@chefstrolley.test')
            ->assertJsonStructure(['data' => ['user', 'tokens' => ['accessToken', 'refreshToken', 'expiresAt']]]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $branch = $this->createBranch();
        User::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@chefstrolley.test',
            'password' => Hash::make('correct-password'),
            'active_branch_id' => $branch->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@chefstrolley.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $branch = $this->createBranch();
        User::create([
            'name' => 'Suspended User',
            'email' => 'suspended@chefstrolley.test',
            'password' => Hash::make('correct-password'),
            'active_branch_id' => $branch->id,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@chefstrolley.test',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_me_endpoint_returns_resolved_permissions(): void
    {
        $this->actingAsUserWithPermissions(['orders.view', 'menu.manage']);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.permissions', fn ($perms) => in_array('orders.view', $perms) && in_array('menu.manage', $perms));
    }
}
