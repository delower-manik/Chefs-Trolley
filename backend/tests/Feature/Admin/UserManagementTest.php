<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private const PASSWORD = 'Str0ngPassw0rd';

    /** Acts as a Super Admin who also holds users.manage (as the seeder grants). */
    private function actingAsSuperAdmin(?Branch $branch = null): User
    {
        $user = $this->actingAsUserWithPermissions(['users.manage'], $branch);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Super Admin'])->id);

        return $user;
    }

    private function roleWith(string $name, array $permissions): Role
    {
        $role = Role::create(['name' => $name]);
        $role->permissions()->sync(
            collect($permissions)->map(fn ($p) => Permission::firstOrCreate(['name' => $p])->id)
        );

        return $role;
    }

    /** A user who is not the acting one -- optionally with a role and branches. */
    private function makeUser(array $branches, ?Role $role = null, array $overrides = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Person '.uniqid(),
            'email' => uniqid().'@chefstrolley.test',
            'password' => Hash::make(self::PASSWORD),
            'active_branch_id' => $branches[0]->id ?? null,
        ], $overrides));
        $user->branches()->sync(collect($branches)->pluck('id'));
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    /** Calls the API with a real bearer token rather than the test-only acting-as shortcut. */
    private function getWithToken(string $uri, string $plainToken)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($uri, ['Authorization' => 'Bearer '.$plainToken]);
    }

    public function test_a_super_admin_can_create_a_user_and_the_password_is_hashed_and_never_returned(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $cashier = $this->roleWith('Cashier', ['orders.create']);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Rahim Uddin',
            'email' => 'Rahim@ChefsTrolley.test',
            'password' => self::PASSWORD,
            'role_ids' => [$cashier->id],
            'branch_ids' => [$branch->id],
        ])->assertStatus(201)
            ->assertJsonPath('data.email', 'rahim@chefstrolley.test') // normalised to lower case
            ->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.activeBranchId', $branch->id)
            ->assertJsonPath('data.roles.0.name', 'Cashier');

        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
        $stored = User::where('email', 'rahim@chefstrolley.test')->firstOrFail();
        $this->assertNotEquals(self::PASSWORD, $stored->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored->password));

        $log = AuditLog::where('action', 'user.created')->firstOrFail();
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($log->changes));
        $this->assertStringNotContainsString('password', strtolower(json_encode($log->changes)));
    }

    public function test_email_must_be_unique_and_the_password_must_be_strong(): void
    {
        $branch = $this->createBranch();
        $actor = $this->actingAsSuperAdmin($branch);
        $role = $this->roleWith('Cashier', ['orders.create']);

        $payload = fn (array $o = []) => array_merge([
            'name' => 'Someone', 'email' => 'someone@chefstrolley.test', 'password' => self::PASSWORD,
            'role_ids' => [$role->id], 'branch_ids' => [$branch->id],
        ], $o);

        $this->postJson('/api/v1/users', $payload(['password' => 'short1']))->assertStatus(422);
        $this->postJson('/api/v1/users', $payload(['password' => 'nodigitsatall']))->assertStatus(422);
        $this->postJson('/api/v1/users', $payload(['email' => strtoupper($actor->email)]))->assertStatus(422);
        $this->postJson('/api/v1/users', $payload())->assertStatus(201);
    }

    public function test_a_plain_edit_cannot_change_a_password(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $target = $this->makeUser([$branch]);

        $this->putJson("/api/v1/users/{$target->id}", ['password' => 'AnotherPassw0rd'])->assertStatus(422);
    }

    public function test_a_limited_admin_cannot_assign_a_role_with_permissions_they_lack_or_the_super_admin_role(): void
    {
        $branch = $this->createBranch();
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view'], $branch);
        $weak = $this->roleWith('Viewer', ['orders.view']);
        $strong = $this->roleWith('Accountant', ['finance.manage']);
        $super = Role::firstOrCreate(['name' => 'Super Admin']);

        $base = ['name' => 'New Hire', 'password' => self::PASSWORD, 'branch_ids' => [$branch->id]];

        $this->postJson('/api/v1/users', $base + ['email' => 'a@chefstrolley.test', 'role_ids' => [$strong->id]])
            ->assertStatus(403)->assertJsonPath('code', 'cannot_assign_role');
        $this->postJson('/api/v1/users', $base + ['email' => 'b@chefstrolley.test', 'role_ids' => [$super->id]])
            ->assertStatus(403)->assertJsonPath('code', 'cannot_assign_role');
        $this->postJson('/api/v1/users', $base + ['email' => 'c@chefstrolley.test', 'role_ids' => [$weak->id]])
            ->assertStatus(201);
        $this->assertDatabaseMissing('users', ['email' => 'a@chefstrolley.test']);
    }

    public function test_a_limited_admin_cannot_assign_a_branch_they_do_not_have(): void
    {
        $mine = $this->createBranch();
        $other = $this->createBranch();
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view'], $mine);
        $role = $this->roleWith('Viewer', ['orders.view']);

        $this->postJson('/api/v1/users', [
            'name' => 'Sneaky', 'email' => 's@chefstrolley.test', 'password' => self::PASSWORD,
            'role_ids' => [$role->id], 'branch_ids' => [$mine->id, $other->id],
        ])->assertStatus(403)->assertJsonPath('code', 'cannot_assign_branch');
    }

    public function test_a_limited_admin_only_sees_users_who_share_a_branch(): void
    {
        $mine = $this->createBranch();
        $other = $this->createBranch();
        $this->actingAsUserWithPermissions(['users.manage'], $mine);
        $colleague = $this->makeUser([$mine], null, ['name' => 'Colleague']);
        $stranger = $this->makeUser([$other], null, ['name' => 'Stranger']);

        $names = collect($this->getJson('/api/v1/users')->assertStatus(200)->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Colleague'));
        $this->assertFalse($names->contains('Stranger'));
        $this->getJson("/api/v1/users/{$colleague->id}")->assertStatus(200);
        $this->getJson("/api/v1/users/{$stranger->id}")->assertStatus(404);
        $this->putJson("/api/v1/users/{$stranger->id}", ['name' => 'Hacked'])->assertStatus(404);
    }

    public function test_a_limited_admin_cannot_modify_or_reset_the_password_of_a_more_powerful_user(): void
    {
        $branch = $this->createBranch();
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view'], $branch);
        $accountant = $this->roleWith('Accountant', ['finance.manage']);
        $target = $this->makeUser([$branch], $accountant);

        $this->putJson("/api/v1/users/{$target->id}", ['name' => 'Renamed'])
            ->assertStatus(403)->assertJsonPath('code', 'cannot_manage_user');
        $this->postJson("/api/v1/users/{$target->id}/password", ['password' => 'TakeOver12345'])->assertStatus(403);
        $this->deleteJson("/api/v1/users/{$target->id}")->assertStatus(403);
        $this->assertTrue(Hash::check(self::PASSWORD, $target->fresh()->password));
    }

    public function test_a_partial_update_works_and_roles_are_replaced(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $cashier = $this->roleWith('Cashier', ['orders.create']);
        $waiter = $this->roleWith('Waiter', ['orders.view']);
        $target = $this->makeUser([$branch], $cashier);

        // Only the name: must not 422 on the other fields (the partial-update regression).
        $this->putJson("/api/v1/users/{$target->id}", ['name' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.roles.0.name', 'Cashier');

        $this->putJson("/api/v1/users/{$target->id}", ['role_ids' => [$waiter->id]])
            ->assertStatus(200)->assertJsonCount(1, 'data.roles')->assertJsonPath('data.roles.0.name', 'Waiter');

        // Both updates can land in the same second, so find the entry by content, not by time.
        $log = AuditLog::where('action', 'user.updated')->get()
            ->first(fn ($l) => $l->changes['after']['roles'] === ['Waiter']);
        $this->assertNotNull($log);
        $this->assertEquals(['Cashier'], $log->changes['before']['roles']);
    }

    public function test_deactivating_a_user_ends_their_access_immediately(): void
    {
        $branch = $this->createBranch();
        $admin = $this->actingAsSuperAdmin($branch);
        $target = $this->makeUser([$branch]);
        $token = $target->createToken('access', ['*'], now()->addMinutes(30))->plainTextToken;

        $this->getWithToken('/api/v1/auth/me', $token)->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum');
        $this->putJson("/api/v1/users/{$target->id}", ['is_active' => false])
            ->assertStatus(200)->assertJsonPath('data.isActive', false);

        $this->assertEquals(0, $target->tokens()->count());
        $this->getWithToken('/api/v1/auth/me', $token)->assertStatus(401);
    }

    public function test_a_password_reset_revokes_sessions_and_the_new_password_works(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $target = $this->makeUser([$branch], null, ['email' => 'victim@chefstrolley.test']);
        $target->createToken('access', ['*']);
        $target->createToken('refresh', ['refresh']);

        $this->postJson("/api/v1/users/{$target->id}/password", ['password' => 'BrandNewPassw0rd'])->assertStatus(200);

        $this->assertEquals(0, $target->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'victim@chefstrolley.test', 'password' => 'BrandNewPassw0rd'])->assertStatus(200);
        $this->postJson('/api/v1/auth/login', ['email' => 'victim@chefstrolley.test', 'password' => self::PASSWORD])->assertStatus(422);

        $log = AuditLog::where('action', 'user.password_reset')->firstOrFail();
        $this->assertNull($log->changes);
    }

    public function test_nobody_can_deactivate_delete_or_re_role_themselves(): void
    {
        $branch = $this->createBranch();
        $actor = $this->actingAsSuperAdmin($branch);
        $other = $this->roleWith('Cashier', ['orders.create']);

        $this->putJson("/api/v1/users/{$actor->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonPath('code', 'cannot_modify_self');
        $this->putJson("/api/v1/users/{$actor->id}", ['role_ids' => [$other->id]])
            ->assertStatus(422)->assertJsonPath('code', 'cannot_modify_self');
        $this->deleteJson("/api/v1/users/{$actor->id}")
            ->assertStatus(422)->assertJsonPath('code', 'cannot_modify_self');
        // Editing their own name is fine.
        $this->putJson("/api/v1/users/{$actor->id}", ['name' => 'Me Again'])->assertStatus(200);
    }

    public function test_a_super_admin_can_deactivate_another_super_admin_while_one_remains(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $second = $this->makeUser([$branch], Role::firstOrCreate(['name' => 'Super Admin']));

        $this->putJson("/api/v1/users/{$second->id}", ['is_active' => false])->assertStatus(200);
        $this->assertFalse($second->fresh()->is_active);
    }

    public function test_deleting_a_user_archives_them_and_revokes_their_tokens(): void
    {
        $branch = $this->createBranch();
        $admin = $this->actingAsSuperAdmin($branch);
        $role = $this->roleWith('Cashier', ['orders.create']);
        $target = $this->makeUser([$branch], null, ['email' => 'gone@chefstrolley.test']);
        $target->createToken('access', ['*']);

        $this->deleteJson("/api/v1/users/{$target->id}")->assertStatus(204);

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertEquals(0, $target->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deleted', 'subject_id' => $target->id]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'gone@chefstrolley.test', 'password' => self::PASSWORD])->assertStatus(422);

        // The address stays reserved by the archived row, matching the database's unique index.
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/v1/users', [
            'name' => 'Replacement', 'email' => 'gone@chefstrolley.test', 'password' => self::PASSWORD,
            'role_ids' => [$role->id], 'branch_ids' => [$branch->id],
        ])->assertStatus(422);
    }

    public function test_options_only_offer_what_the_actor_may_assign(): void
    {
        $mine = $this->createBranch(['name' => 'Mine']);
        $this->createBranch(['name' => 'Not Mine']);
        $this->actingAsUserWithPermissions(['users.manage', 'orders.view'], $mine);
        $this->roleWith('Viewer', ['orders.view']);
        $this->roleWith('Accountant', ['finance.manage']);
        Role::firstOrCreate(['name' => 'Super Admin']);

        $response = $this->getJson('/api/v1/users/options')->assertStatus(200);

        $roles = collect($response->json('data.roles'))->pluck('name');
        $this->assertTrue($roles->contains('Viewer'));
        $this->assertFalse($roles->contains('Accountant'));
        $this->assertFalse($roles->contains('Super Admin'));
        $this->assertEquals(['Mine'], collect($response->json('data.branches'))->pluck('name')->all());
    }

    public function test_users_can_be_searched_and_filtered_by_status(): void
    {
        $branch = $this->createBranch();
        $this->actingAsSuperAdmin($branch);
        $this->makeUser([$branch], null, ['name' => 'Fatima Begum', 'email' => 'fatima@chefstrolley.test']);
        $this->makeUser([$branch], null, ['name' => 'Karim Mia', 'email' => 'karim@chefstrolley.test', 'is_active' => false]);

        $names = fn (string $qs) => collect($this->getJson('/api/v1/users?'.$qs)->assertStatus(200)->json('data'))->pluck('name')->all();

        $this->assertEquals(['Fatima Begum'], $names('q=FATIMA'));           // case-insensitive, by name
        $this->assertEquals(['Karim Mia'], $names('q=karim@chefs'));          // by email
        $this->assertEquals([], $names('q=%25'));                             // '%' is literal, not a wildcard
        $this->assertEquals(['Karim Mia'], $names('status=inactive'));
    }

    public function test_user_management_requires_the_users_manage_permission(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);

        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->getJson('/api/v1/users/options')->assertStatus(403);
        $this->postJson('/api/v1/users', [])->assertStatus(403);
    }
}
