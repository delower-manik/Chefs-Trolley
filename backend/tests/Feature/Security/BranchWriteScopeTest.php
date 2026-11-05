<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Ingredient;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Tests\TestCase;

/**
 * A user may only write into branches they can access. Every request that
 * takes a `branch_id` from the body goes through the same rule, so these
 * tests cover a representative spread of endpoints.
 */
class BranchWriteScopeTest extends TestCase
{
    private Branch $mine;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine = $this->createBranch(['name' => 'Mine']);
        $this->other = $this->createBranch(['name' => 'Someone else']);
    }

    private function asManager(array $permissions): User
    {
        return $this->actingAsUserWithPermissions($permissions, $this->mine);
    }

    public function test_a_category_cannot_be_created_in_a_branch_the_user_cannot_access(): void
    {
        $this->asManager(['menu.manage']);

        $this->postJson('/api/v1/categories', ['name' => 'Pizza', 'branch_id' => $this->other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_a_table_cannot_be_created_in_a_branch_the_user_cannot_access(): void
    {
        $this->asManager(['tables.manage']);

        $this->postJson('/api/v1/tables', ['branch_id' => $this->other->id, 'label' => 'T1', 'capacity' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_an_ingredient_and_a_supplier_cannot_be_created_in_a_foreign_branch(): void
    {
        $this->asManager(['inventory.manage']);
        $unit = Unit::create(['name' => 'Kilogram', 'abbreviation' => 'kg-'.uniqid()]);

        $this->postJson('/api/v1/ingredients', ['branch_id' => $this->other->id, 'unit_id' => $unit->id, 'name' => 'Flour'])
            ->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/v1/suppliers', ['branch_id' => $this->other->id, 'name' => 'Acme'])
            ->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_a_customer_cannot_be_created_in_a_foreign_branch(): void
    {
        $this->asManager(['customers.manage']);

        $this->postJson('/api/v1/customers', ['branch_id' => $this->other->id, 'name' => 'A', 'phone' => '01700000001'])
            ->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_a_record_cannot_be_moved_to_a_foreign_branch_by_an_update(): void
    {
        $this->asManager(['tables.manage']);
        $table = DiningTable::create(['branch_id' => $this->mine->id, 'label' => 'T9', 'capacity' => 2]);

        $this->putJson("/api/v1/tables/{$table->id}", ['branch_id' => $this->other->id])
            ->assertStatus(422)->assertJsonValidationErrors('branch_id');

        $this->assertSame($this->mine->id, $table->fresh()->branch_id);
    }

    public function test_omitting_branch_id_uses_the_users_active_branch_instead_of_creating_a_chain_wide_record(): void
    {
        $this->asManager(['menu.manage']);

        $this->postJson('/api/v1/categories', ['name' => 'Drinks'])->assertStatus(201);

        $this->assertSame($this->mine->id, Category::where('name', 'Drinks')->value('branch_id'));
    }

    public function test_a_super_admin_may_write_to_any_branch(): void
    {
        $user = $this->asManager(['menu.manage']);
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user->roles()->attach($role->id);

        $this->postJson('/api/v1/categories', ['name' => 'Global', 'branch_id' => $this->other->id])->assertStatus(201);
        $this->assertSame($this->other->id, Category::where('name', 'Global')->value('branch_id'));
    }

    public function test_the_same_table_label_can_exist_in_two_branches_but_not_twice_in_one(): void
    {
        $user = $this->asManager(['tables.manage']);
        $user->branches()->attach($this->other->id);

        $this->postJson('/api/v1/tables', ['branch_id' => $this->mine->id, 'label' => 'T1', 'capacity' => 4])->assertStatus(201);
        $this->postJson('/api/v1/tables', ['branch_id' => $this->other->id, 'label' => 'T1', 'capacity' => 4])->assertStatus(201);
        $this->postJson('/api/v1/tables', ['branch_id' => $this->mine->id, 'label' => 'T1', 'capacity' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('label');
    }

    public function test_duplicate_ingredient_and_warehouse_names_are_validation_errors_not_server_errors(): void
    {
        $this->asManager(['inventory.manage']);
        $unit = Unit::create(['name' => 'Litre', 'abbreviation' => 'l-'.uniqid()]);

        $payload = ['branch_id' => $this->mine->id, 'unit_id' => $unit->id, 'name' => 'Oil'];
        $this->postJson('/api/v1/ingredients', $payload)->assertStatus(201);
        $this->postJson('/api/v1/ingredients', $payload)->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/warehouses', ['name' => 'Cold store'])->assertStatus(201);
        $this->postJson('/api/v1/warehouses', ['name' => 'Cold store'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame(1, Ingredient::where('name', 'Oil')->count());
    }

    public function test_duplicate_category_names_are_rejected_and_untransliterable_names_do_not_collide(): void
    {
        $this->asManager(['menu.manage']);

        $this->postJson('/api/v1/categories', ['name' => 'Burgers'])->assertStatus(201);
        $this->postJson('/api/v1/categories', ['name' => 'burgers'])->assertStatus(422)->assertJsonValidationErrors('name');

        // Names that slugify to an empty string must still get distinct slugs.
        $this->postJson('/api/v1/categories', ['name' => '🍔'])->assertStatus(201);
        $this->postJson('/api/v1/categories', ['name' => '🍕'])->assertStatus(201);
    }

    public function test_a_duplicate_payroll_period_is_a_validation_error(): void
    {
        $this->asManager(['payroll.manage']);
        $payload = ['period_start' => '2026-03-01', 'period_end' => '2026-03-31'];

        $this->postJson('/api/v1/payroll/periods', $payload)->assertStatus(201);
        $this->postJson('/api/v1/payroll/periods', $payload)->assertStatus(422)->assertJsonValidationErrors('period_end');
    }
}
