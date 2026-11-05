<?php

namespace Tests\Feature\Restaurant;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\TaxRate;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

class WaiterOrderTest extends TestCase
{
    use BuildsMenu;

    private array $perms = ['orders.view', 'orders.create', 'tables.manage'];

    private function setupTab(): array
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $table = DiningTable::create(['branch_id' => $b, 'label' => 'T1', 'capacity' => 4]);
        $dish = $this->makeFoodItem($b, 100);
        $id = $this->postJson('/api/v1/orders', [
            'source' => 'dine_in', 'dining_table_id' => $table->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.diningTableId', $table->id)->json('data.id');

        return [$user, $table, $dish, Order::findOrFail($id)];
    }

    public function test_opening_a_tab_links_the_order_and_occupies_the_table(): void
    {
        [, $table] = $this->setupTab();

        $this->assertSame('occupied', $table->fresh()->status);
    }

    public function test_a_second_order_on_a_busy_table_is_refused_with_the_existing_order(): void
    {
        [, $table, $dish, $order] = $this->setupTab();

        $this->postJson('/api/v1/orders', [
            'source' => 'dine_in', 'dining_table_id' => $table->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'table_has_open_order')->assertJsonPath('errors.orderCode', $order->code);
    }

    public function test_table_must_belong_to_the_branch_and_be_in_service(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $dish = $this->makeFoodItem(null, 100);
        $foreign = DiningTable::create(['branch_id' => $this->createBranch()->id, 'label' => 'X', 'capacity' => 2]);
        $broken = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'B', 'capacity' => 2, 'status' => 'out_of_service']);
        $payload = fn ($t) => ['source' => 'dine_in', 'dining_table_id' => $t->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/orders', $payload($foreign))->assertStatus(422)->assertJsonPath('code', 'invalid_table');
        $this->postJson('/api/v1/orders', $payload($broken))->assertStatus(422)->assertJsonPath('code', 'table_out_of_service');
        $this->assertSame('available', $foreign->fresh()->status, 'other branch table untouched');
    }

    public function test_held_order_does_not_occupy_the_table(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $table = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'H1', 'capacity' => 2]);
        $dish = $this->makeFoodItem(null, 100);

        $this->postJson('/api/v1/orders', ['source' => 'dine_in', 'hold' => true, 'dining_table_id' => $table->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])
            ->assertStatus(201);

        $this->assertSame('available', $table->fresh()->status);
    }

    public function test_adding_a_round_grows_totals_reopens_the_kitchen_and_debits_stock(): void
    {
        [$user, , , $order] = $this->setupTab();
        $b = $user->active_branch_id;
        TaxRate::create(['branch_id' => $b, 'name' => 'VAT', 'rate' => 10, 'is_default' => true]);
        $drink = $this->makeFoodItem($b, 50);
        $ing = $this->giveRecipe($drink, $b, 2, 20);
        $order->update(['kitchen_state' => 'ready', 'status' => 'ready']);
        $order->items()->update(['kitchen_state' => 'ready']);

        $this->postJson("/api/v1/orders/{$order->id}/items", ['items' => [['food_item_id' => $drink->id, 'quantity' => 3]]])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.subtotal', 250)
            ->assertJsonPath('data.taxTotal', 15)
            ->assertJsonPath('data.grandTotal', 265)
            ->assertJsonPath('data.kitchenState', 'new')
            ->assertJsonPath('data.status', 'pending');

        $this->assertEquals(14, $ing->totalStock());
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.items_added', 'subject_id' => $order->id]);
    }

    public function test_insufficient_stock_rolls_the_whole_round_back(): void
    {
        [$user, , , $order] = $this->setupTab();
        $drink = $this->makeFoodItem($user->active_branch_id, 50);
        $this->giveRecipe($drink, $user->active_branch_id, 10, 15);

        $this->postJson("/api/v1/orders/{$order->id}/items", ['items' => [['food_item_id' => $drink->id, 'quantity' => 2]]])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

        $this->assertCount(1, $order->fresh()->items);
        $this->assertEquals(100, $order->fresh()->subtotal);
    }

    public function test_a_closed_or_held_order_cannot_take_more_items(): void
    {
        [, , $dish, $order] = $this->setupTab();
        $payload = ['items' => [['food_item_id' => $dish->id, 'quantity' => 1]]];

        foreach (['cancelled', 'completed', 'draft'] as $status) {
            $order->update(['status' => $status]);
            $this->postJson("/api/v1/orders/{$order->id}/items", $payload)->assertStatus(422)->assertJsonPath('code', 'order_closed');
        }
    }

    public function test_adding_items_needs_orders_create_and_branch_access(): void
    {
        [$user, , $dish, $order] = $this->setupTab();
        $payload = ['items' => [['food_item_id' => $dish->id, 'quantity' => 1]]];

        $this->actingAsUserWithPermissions(['orders.view'], $user->activeBranch);
        $this->postJson("/api/v1/orders/{$order->id}/items", $payload)->assertStatus(403);

        $this->actingAsUserWithPermissions($this->perms, $this->createBranch()); // a user of a different branch
        $this->postJson("/api/v1/orders/{$order->id}/items", $payload)->assertStatus(404);
    }

    public function test_transferring_a_tab_moves_occupancy(): void
    {
        [$user, $t1, , $order] = $this->setupTab();
        $t2 = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T2', 'capacity' => 4]);

        $this->postJson("/api/v1/orders/{$order->id}/transfer-table", ['dining_table_id' => $t2->id])
            ->assertStatus(200)->assertJsonPath('data.diningTableId', $t2->id);

        $this->assertSame('available', $t1->fresh()->status);
        $this->assertSame('occupied', $t2->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.table_transferred', 'subject_id' => $order->id]);
    }

    public function test_transfer_rejects_busy_same_foreign_and_broken_tables(): void
    {
        [$user, $t1, $dish, $order] = $this->setupTab();
        $b = $user->active_branch_id;
        $busy = DiningTable::create(['branch_id' => $b, 'label' => 'T2', 'capacity' => 4]);
        $this->postJson('/api/v1/orders', ['source' => 'dine_in', 'dining_table_id' => $busy->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->assertStatus(201);
        $foreign = DiningTable::create(['branch_id' => $this->createBranch()->id, 'label' => 'F', 'capacity' => 2]);
        $broken = DiningTable::create(['branch_id' => $b, 'label' => 'B', 'capacity' => 2, 'status' => 'out_of_service']);

        $url = "/api/v1/orders/{$order->id}/transfer-table";
        $this->postJson($url, ['dining_table_id' => $busy->id])->assertStatus(422)->assertJsonPath('code', 'table_has_open_order');
        $this->postJson($url, ['dining_table_id' => $t1->id])->assertStatus(422)->assertJsonPath('code', 'same_table');
        $this->postJson($url, ['dining_table_id' => $foreign->id])->assertStatus(422)->assertJsonPath('code', 'invalid_table');
        $this->postJson($url, ['dining_table_id' => $broken->id])->assertStatus(422)->assertJsonPath('code', 'table_out_of_service');
    }

    public function test_cancelling_or_completing_frees_the_table(): void
    {
        [$user, $t1, , $order] = $this->setupTab();
        $this->actingAsUserWithPermissions(['orders.view', 'orders.cancel', 'kds.access'], $user->activeBranch);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Guests left'])->assertStatus(200);
        $this->assertSame('available', $t1->fresh()->status);

        // completion via the kitchen
        $t2 = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T9', 'capacity' => 2]);
        $dish = $this->makeFoodItem(null, 10);
        $this->actingAsUserWithPermissions($this->perms, $user->activeBranch);
        $id = $this->postJson('/api/v1/orders', ['source' => 'dine_in', 'dining_table_id' => $t2->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->json('data.id');
        $this->actingAsUserWithPermissions(['kds.access'], $user->activeBranch);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/kitchen/tickets/{$id}/bump")->assertStatus(200);
        }
        $this->assertSame('available', $t2->fresh()->status);
    }

    public function test_waiter_can_load_the_catalog_without_menu_manage(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $dish = $this->makeFoodItem($b, 100, ['name' => 'Visible']);
        $this->makeFoodItem($b, 100, ['name' => 'Hidden', 'is_available' => false]);
        $this->makeFoodItem($this->createBranch()->id, 100, ['name' => 'Elsewhere']);
        $table = DiningTable::create(['branch_id' => $b, 'label' => 'T1', 'capacity' => 2]);

        $res = $this->getJson('/api/v1/pos/catalog')->assertStatus(200);
        $this->assertSame(['Visible'], collect($res->json('data.items'))->pluck('name')->all());
        $res->assertJsonPath('data.tables.0.id', $table->id)->assertJsonPath('data.tables.0.openOrder', null);

        $this->getJson('/api/v1/food-items')->assertStatus(403);
    }

    public function test_catalog_shows_the_open_tab_on_a_table(): void
    {
        [, $table, , $order] = $this->setupTab();

        $this->getJson('/api/v1/pos/catalog')->assertJsonPath('data.tables.0.openOrder.code', $order->code);
    }
}
