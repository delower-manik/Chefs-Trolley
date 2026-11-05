<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    private function actingAsSuperAdmin(): User
    {
        $user = $this->actingAsUserWithPermissions(['users.manage']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Super Admin'])->id);

        return $user;
    }

    private function seedPermissions(array $names): void
    {
        foreach ($names as $n) {
            Permission::firstOrCreate(['name' => $n]);
        }
    }

    private function roleWith(string $name, array $permissions): Role
    {
        $this->seedPermissions($permissions);
        $role = Role::create(['name' => $name]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return $role;
    }

    public function test_a_role_can_be_created_with_a_set_of_permissions_and_is_audited(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedPermissions(['orders.view', 'orders.create']);

        $this->postJson('/api/v1/roles', [
            'name' => 'Shift Lead', 'description' => 'Runs the floor', 'permissions' => ['orders.view', 'orders.create'],
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Shift Lead')
            ->assertJsonPath('data.userCount', 0)
            ->assertJsonPath('data.permissions', ['orders.create', 'orders.view']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'role.created']);
    }

    public function test_unknown_permissions_and_a_second_super_admin_role_are_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedPermissions(['orders.view']);

        $this->postJson('/api/v1/roles', ['name' => 'Odd', 'permissions' => ['made.up']])->assertStatus(422);
        $this->postJson('/api/v1/roles', ['name' => 'Super Admin', 'permissions' => ['orders.view']])->assertStatus(422);
        $this->postJson('/api/v1/roles', ['name' => 'Odd', 'permissions' => ['orders.view']])->assertStatus(201);
        $this->postJson('/api/v1/roles', ['name' => 'Odd', 'permissions' => ['orders.view']])->assertStatus(422); // duplicate name
    }

    public function test_the_super_admin_role_cannot_be_changed_or_deleted(): void
    {
        $this->actingAsSuperAdmin();
        $super = Role::where('name', 'Super Admin')->firstOrFail();

        $this->putJson("/api/v1/roles/{$super->id}", ['description' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'system_role');
        $this->deleteJson("/api/v1/roles/{$super->id}")
            ->assertStatus(422)->assertJsonPath('code', 'system_role');
    }

    public function test_updating_permissions_records_what_was_added_and_removed(): void
    {
        $this->actingAsSuperAdmin();
        $role = $this->roleWith('Cashier', ['orders.view', 'orders.create']);
        $this->seedPermissions(['pos.access']);

        $this->putJson("/api/v1/roles/{$role->id}", ['permissions' => ['orders.view', 'pos.access']])
            ->assertStatus(200)->assertJsonPath('data.permissions', ['orders.view', 'pos.access']);

        $log = AuditLog::where('action', 'role.updated')->firstOrFail();
        $this->assertEquals(['pos.access'], $log->changes['permissions_added']);
        $this->assertEquals(['orders.create'], $log->changes['permissions_removed']);
    }

    public function test_a_partial_update_leaves_the_permissions_alone(): void
    {
        $this->actingAsSuperAdmin();
        $role = $this->roleWith('Cashier', ['orders.view']);

        $this->putJson("/api/v1/roles/{$role->id}", ['description' => 'Front counter'])
            ->assertStatus(200)
            ->assertJsonPath('data.description', 'Front counter')
            ->assertJsonPath('data.permissions', ['orders.view']);
    }

    public function test_a_limited_admin_can_only_use_permissions_they_hold(): void
    {
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view']);
        $this->seedPermissions(['finance.manage']);

        $this->postJson('/api/v1/roles', ['name' => 'Sneaky', 'permissions' => ['finance.manage']])
            ->assertStatus(403)->assertJsonPath('code', 'cannot_grant_permission');
        $this->postJson('/api/v1/roles', ['name' => 'Fine', 'permissions' => ['orders.view']])->assertStatus(201);
        $this->assertDatabaseMissing('roles', ['name' => 'Sneaky']);
    }

    public function test_a_limited_admin_cannot_edit_or_delete_a_role_that_exceeds_their_access(): void
    {
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view']);
        $powerful = $this->roleWith('Accountant', ['finance.manage', 'orders.view']);

        $this->putJson("/api/v1/roles/{$powerful->id}", ['permissions' => ['orders.view']])
            ->assertStatus(403)->assertJsonPath('code', 'role_exceeds_your_access');
        $this->deleteJson("/api/v1/roles/{$powerful->id}")
            ->assertStatus(403)->assertJsonPath('code', 'role_exceeds_your_access');

        $this->assertEquals(2, $powerful->fresh()->permissions()->count());
    }

    public function test_a_role_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->actingAsSuperAdmin();
        $used = $this->roleWith('Waiter', ['orders.view']);
        $unused = $this->roleWith('Temp', ['orders.view']);
        $holder = User::create(['name' => 'W', 'email' => 'w@chefstrolley.test', 'password' => 'Str0ngPassw0rd']);
        $holder->roles()->attach($used->id);

        $this->deleteJson("/api/v1/roles/{$used->id}")->assertStatus(422)->assertJsonPath('code', 'role_in_use');
        $this->deleteJson("/api/v1/roles/{$unused->id}")->assertStatus(204);

        $this->assertDatabaseMissing('roles', ['id' => $unused->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted', 'subject_id' => $unused->id]);
    }

    public function test_the_listing_reports_user_counts_and_whether_a_role_is_editable(): void
    {
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view']);
        $this->roleWith('Viewer', ['orders.view']);
        $this->roleWith('Accountant', ['finance.manage']);
        Role::firstOrCreate(['name' => 'Super Admin']);

        $roles = collect($this->getJson('/api/v1/roles')->assertStatus(200)->json('data'))->keyBy('name');

        $this->assertTrue($roles['Viewer']['editable']);
        $this->assertFalse($roles['Accountant']['editable']);
        $this->assertFalse($roles['Super Admin']['editable']);
        $this->assertTrue($roles['Super Admin']['isSystem']);
    }

    public function test_the_permission_catalogue_marks_what_the_actor_may_grant(): void
    {
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view']);
        $this->seedPermissions(['finance.manage']);

        $catalogue = collect($this->getJson('/api/v1/permissions')->assertStatus(200)->json('data'))->keyBy('name');

        $this->assertTrue($catalogue['orders.view']['grantable']);
        $this->assertFalse($catalogue['finance.manage']['grantable']);
    }

    public function test_role_management_requires_users_manage(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);

        $this->getJson('/api/v1/roles')->assertStatus(403);
        $this->getJson('/api/v1/permissions')->assertStatus(403);
        $this->postJson('/api/v1/roles', [])->assertStatus(403);
    }
}
