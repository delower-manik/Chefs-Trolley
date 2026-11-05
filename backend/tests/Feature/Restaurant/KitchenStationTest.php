<?php

namespace Tests\Feature\Restaurant;

use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Order;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

class KitchenStationTest extends TestCase
{
    use BuildsMenu;

    private array $perms = ['orders.create', 'orders.view', 'kds.access', 'kitchen.manage', 'menu.manage'];

    /** An order with a grill dish, a bar dish and an unrouted dish. */
    private function mixedOrder(): array
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $grill = KitchenStation::create(['branch_id' => $b, 'name' => 'Grill']);
        $bar = KitchenStation::create(['branch_id' => $b, 'name' => 'Bar']);

        $steak = $this->makeFoodItem($b, 500, ['name' => 'Steak', 'kitchen_station_id' => $grill->id]);
        $juice = $this->makeFoodItem($b, 100, ['name' => 'Juice', 'kitchen_station_id' => $bar->id]);
        $bread = $this->makeFoodItem($b, 50, ['name' => 'Bread']);

        $id = $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [
            ['food_item_id' => $steak->id, 'quantity' => 1],
            ['food_item_id' => $juice->id, 'quantity' => 2],
            ['food_item_id' => $bread->id, 'quantity' => 1],
        ]])->assertStatus(201)->json('data.id');

        return [Order::findOrFail($id), $grill, $bar];
    }

    public function test_order_lines_snapshot_their_station(): void
    {
        [$order, $grill, $bar] = $this->mixedOrder();

        $byName = $order->items->keyBy('name');
        $this->assertSame($grill->id, $byName['Steak']->kitchen_station_id);
        $this->assertSame($bar->id, $byName['Juice']->kitchen_station_id);
        $this->assertNull($byName['Bread']->kitchen_station_id);
        $this->assertSame('new', $byName['Steak']->kitchen_state);
    }

    public function test_station_board_shows_only_that_stations_lines(): void
    {
        [$order, $grill, $bar] = $this->mixedOrder();

        $res = $this->getJson('/api/v1/kitchen/tickets?station_id='.$grill->id)->assertStatus(200);
        $res->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.items')->assertJsonPath('data.0.items.0.name', 'Steak');

        $this->getJson('/api/v1/kitchen/tickets?station_id='.$bar->id)->assertJsonPath('data.0.items.0.name', 'Juice');
        $this->getJson('/api/v1/kitchen/tickets?station_id=none')->assertJsonPath('data.0.items.0.name', 'Bread');
        $this->getJson('/api/v1/kitchen/tickets')->assertJsonCount(3, 'data.0.items'); // unfiltered = whole order
    }

    public function test_stations_advance_independently_and_order_state_follows_the_slowest(): void
    {
        [$order, $grill, $bar] = $this->mixedOrder();

        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump?station_id={$grill->id}")
            ->assertStatus(200)->assertJsonPath('data.kitchenState', 'accepted');

        $order->refresh();
        $this->assertSame('new', $order->kitchen_state, 'bar and unrouted lines are still new');
        $this->assertSame('pending', $order->status);

        // Finish the other two groups too: order becomes "accepted" only once all are.
        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump?station_id={$bar->id}")->assertStatus(200);
        $this->assertSame('new', $order->fresh()->kitchen_state);
        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump?station_id=none")->assertStatus(200);

        $order->refresh();
        $this->assertSame('accepted', $order->kitchen_state);
        $this->assertSame('accepted', $order->status);
    }

    public function test_a_finished_station_drops_off_its_board_but_not_the_others(): void
    {
        [$order, $grill, $bar] = $this->mixedOrder();

        foreach (['accepted', 'preparing', 'ready', 'served'] as $_) {
            $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump?station_id={$grill->id}")->assertStatus(200);
        }

        $this->getJson('/api/v1/kitchen/tickets?station_id='.$grill->id)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/kitchen/tickets?station_id='.$bar->id)->assertJsonCount(1, 'data');
    }

    public function test_bump_without_station_moves_every_line(): void
    {
        [$order] = $this->mixedOrder();

        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump")->assertStatus(200)->assertJsonPath('data.kitchenState', 'accepted');

        $this->assertSame(['accepted'], $order->fresh('items')->items->pluck('kitchen_state')->unique()->values()->all());
    }

    public function test_reopen_for_a_station_steps_only_its_lines_back(): void
    {
        [$order, $grill, $bar] = $this->mixedOrder();
        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump?station_id={$grill->id}")->assertStatus(200);

        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/reopen?station_id={$grill->id}")
            ->assertStatus(200)->assertJsonPath('data.kitchenState', 'new');
    }

    public function test_unknown_station_for_a_ticket_is_a_422(): void
    {
        [$order] = $this->mixedOrder();
        $other = KitchenStation::create(['branch_id' => $order->branch_id, 'name' => 'Dessert']);

        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump?station_id={$other->id}")
            ->assertStatus(422)->assertJsonPath('code', 'no_items_for_station');
    }

    public function test_inactive_station_is_not_snapshotted(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $off = KitchenStation::create(['branch_id' => $b, 'name' => 'Closed', 'is_active' => false]);
        $dish = $this->makeFoodItem($b, 100, ['kitchen_station_id' => $off->id]);

        $id = $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->json('data.id');

        $this->assertNull(Order::find($id)->items->first()->kitchen_station_id);
    }

    // ---- management API ----

    public function test_station_crud_with_unique_names_and_audit(): void
    {
        $this->actingAsUserWithPermissions($this->perms);

        $id = $this->postJson('/api/v1/kitchen/stations', ['name' => 'Fry'])->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/kitchen/stations', ['name' => 'Fry'])->assertStatus(422);
        $this->putJson("/api/v1/kitchen/stations/{$id}", ['name' => 'Deep Fry'])->assertStatus(200)->assertJsonPath('data.name', 'Deep Fry');
        $this->getJson('/api/v1/kitchen/stations')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/kitchen/stations/{$id}")->assertStatus(204);

        $this->assertDatabaseHas('audit_logs', ['action' => 'kitchen_station.created', 'subject_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kitchen_station.deleted', 'subject_id' => $id]);
    }

    public function test_listing_needs_kds_access_and_editing_needs_kitchen_manage(): void
    {
        $this->actingAsUserWithPermissions(['kds.access']);
        $this->getJson('/api/v1/kitchen/stations')->assertStatus(200);
        $this->postJson('/api/v1/kitchen/stations', ['name' => 'Nope'])->assertStatus(403);
    }

    public function test_food_item_can_only_use_a_station_from_its_own_branch(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);
        $foreign = KitchenStation::create(['branch_id' => $this->createBranch()->id, 'name' => 'Foreign']);
        $category = Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()]);

        $this->postJson('/api/v1/food-items', [
            'category_id' => $category->id, 'name' => 'Soup', 'base_price' => 100, 'kitchen_station_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('kitchen_station_id');
    }
}
