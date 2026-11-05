<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    private function actingAsSuperAdmin(?Branch $branch = null): User
    {
        $user = $this->actingAsUserWithPermissions(['branches.manage'], $branch);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Super Admin'])->id);

        return $user;
    }

    public function test_a_branch_can_be_created_with_a_default_warehouse_and_the_creator_is_assigned_to_it(): void
    {
        $actor = $this->actingAsUserWithPermissions(['branches.manage']);

        $response = $this->postJson('/api/v1/branches', [
            'name' => 'Banani', 'code' => 'bnn-01', 'timezone' => 'Asia/Dhaka', 'phone' => '01700000000',
        ])->assertStatus(201)
            ->assertJsonPath('data.code', 'BNN-01') // normalised to upper case
            ->assertJsonPath('data.timezone', 'Asia/Dhaka');

        $id = $response->json('data.id');
        $this->assertTrue($actor->branches()->where('branches.id', $id)->exists());
        $this->assertDatabaseHas('warehouses', ['branch_id' => $id, 'is_default' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.created', 'subject_id' => $id, 'branch_id' => $id]);
    }

    public function test_branch_codes_must_be_unique_well_formed_and_timezones_real(): void
    {
        $this->actingAsSuperAdmin();
        $ok = ['name' => 'One', 'code' => 'ONE', 'timezone' => 'Asia/Dhaka'];

        $this->postJson('/api/v1/branches', $ok)->assertStatus(201);
        $this->postJson('/api/v1/branches', array_merge($ok, ['name' => 'Two']))->assertStatus(422); // duplicate code
        $this->postJson('/api/v1/branches', array_merge($ok, ['code' => 'bad code!']))->assertStatus(422);
        $this->postJson('/api/v1/branches', array_merge($ok, ['code' => 'TWO', 'timezone' => 'Mars/Olympus']))->assertStatus(422);
    }

    public function test_a_partial_update_changes_only_what_was_sent_and_is_audited(): void
    {
        $this->actingAsSuperAdmin();
        $branch = $this->createBranch(['name' => 'Gulshan', 'code' => 'GUL']);

        $this->putJson("/api/v1/branches/{$branch->id}", ['timezone' => 'Asia/Kolkata'])
            ->assertStatus(200)
            ->assertJsonPath('data.timezone', 'Asia/Kolkata')
            ->assertJsonPath('data.name', 'Gulshan');

        $this->putJson("/api/v1/branches/{$branch->id}", ['code' => 'GUL']) // its own code is not a duplicate
            ->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.updated', 'subject_id' => $branch->id]);
    }

    public function test_a_branch_cannot_take_another_branchs_code(): void
    {
        $this->actingAsSuperAdmin();
        $this->createBranch(['code' => 'TAKEN']);
        $branch = $this->createBranch(['code' => 'MINE']);

        $this->putJson("/api/v1/branches/{$branch->id}", ['code' => 'taken'])->assertStatus(422);
    }

    public function test_a_limited_admin_only_sees_and_edits_their_own_branches(): void
    {
        $mine = $this->createBranch(['name' => 'Mine']);
        $theirs = $this->createBranch(['name' => 'Theirs']);
        $this->actingAsUserWithPermissions(['branches.manage'], $mine);

        $names = collect($this->getJson('/api/v1/branches')->assertStatus(200)->json('data'))->pluck('name')->all();
        $this->assertEquals(['Mine'], $names);

        $this->putJson("/api/v1/branches/{$mine->id}", ['name' => 'Mine Renamed'])->assertStatus(200);
        $this->putJson("/api/v1/branches/{$theirs->id}", ['name' => 'Hijacked'])->assertStatus(404);
        $this->assertEquals('Theirs', $theirs->fresh()->name);
    }

    public function test_a_super_admin_sees_every_branch(): void
    {
        $this->actingAsSuperAdmin();
        $this->createBranch(['name' => 'A']);
        $this->createBranch(['name' => 'B']);

        $names = collect($this->getJson('/api/v1/branches')->json('data'))->pluck('name');

        $this->assertTrue($names->contains('A') && $names->contains('B'));
    }

    public function test_branch_management_requires_the_branches_manage_permission(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);

        $this->getJson('/api/v1/branches')->assertStatus(403);
        $this->postJson('/api/v1/branches', [])->assertStatus(403);
    }
}
