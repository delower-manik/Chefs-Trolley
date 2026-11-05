<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Unit;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

/** Knowing another branch's record id must not allow reading or changing it. */
class RouteModelBranchTest extends TestCase
{
    use BuildsMenu;

    public function test_cannot_edit_or_delete_another_branchs_table_by_id(): void
    {
        $this->actingAsUserWithPermissions(['tables.manage']);
        $foreign = DiningTable::create(['branch_id' => $this->createBranch()->id, 'label' => 'F1', 'capacity' => 2]);

        $this->putJson("/api/v1/tables/{$foreign->id}", ['status' => 'out_of_service'])->assertStatus(404);
        $this->deleteJson("/api/v1/tables/{$foreign->id}")->assertStatus(404);
        $this->assertSame('available', $foreign->fresh()->status);
    }

    public function test_cannot_touch_another_branchs_menu_or_inventory_records(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage', 'inventory.manage']);
        $other = $this->createBranch();
        $category = Category::create(['branch_id' => $other->id, 'name' => 'Theirs', 'slug' => 'theirs']);
        $item = $this->makeFoodItem($other->id, 100);
        $ingredient = Ingredient::create(['branch_id' => $other->id, 'unit_id' => Unit::create(['name' => 'g', 'abbreviation' => 'g'.uniqid()])->id, 'name' => 'Salt']);

        $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Mine now'])->assertStatus(404);
        $this->deleteJson("/api/v1/food-items/{$item->id}")->assertStatus(404);
        $this->getJson("/api/v1/food-items/{$item->id}")->assertStatus(404);
        $this->deleteJson("/api/v1/ingredients/{$ingredient->id}")->assertStatus(404);
        $this->assertNotNull($item->fresh());
    }

    public function test_cannot_bump_or_cancel_another_branchs_order(): void
    {
        $this->actingAsUserWithPermissions(['kds.access', 'orders.cancel', 'orders.view']);
        $order = Order::create(['code' => 'CT-X', 'source' => 'pos', 'branch_id' => $this->createBranch()->id, 'status' => 'pending']);

        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump")->assertStatus(404);
        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'x'])->assertStatus(404);
        $this->assertSame('new', $order->fresh()->kitchen_state);
    }

    public function test_super_admin_can_reach_any_branch(): void
    {
        $user = $this->actingAsUserWithPermissions(['tables.manage']);
        $user->roles()->first()->update(['name' => 'Super Admin']);
        $foreign = DiningTable::create(['branch_id' => $this->createBranch()->id, 'label' => 'F1', 'capacity' => 2]);

        $this->putJson("/api/v1/tables/{$foreign->id}", ['status' => 'reserved'])->assertStatus(200);
    }

    public function test_chain_wide_records_stay_editable_with_the_permission(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);
        $category = Category::create(['name' => 'Everywhere', 'slug' => 'everywhere']);

        $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Renamed'])->assertStatus(200);
    }
}
