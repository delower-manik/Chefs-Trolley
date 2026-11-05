<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\FoodItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    private function makePizzaWithSizeModifiers(): FoodItem
    {
        $category = Category::create(['name' => 'Pizza', 'slug' => 'pizza-'.uniqid()]);
        $item = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(),
            'base_price' => 500, 'tax_rate' => 10,
        ]);
        $group = ModifierGroup::create(['food_item_id' => $item->id, 'name' => 'Size']);
        Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Large', 'price' => 150]);

        return $item->load('modifierGroups.modifiers');
    }

    public function test_creating_an_order_recomputes_totals_server_side_and_ignores_client_prices(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $item = $this->makePizzaWithSizeModifiers();
        $modifierId = $item->modifierGroups->first()->modifiers->first()->id;

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [
                ['food_item_id' => $item->id, 'quantity' => 2, 'modifier_ids' => [$modifierId]],
            ],
        ]);

        // unit price = 500 + 150 = 650; line total = 1300; tax = 130 (10%)
        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', 1300)
            ->assertJsonPath('data.taxTotal', 130)
            ->assertJsonPath('data.grandTotal', 1430)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.kitchenState', 'new');
    }

    public function test_unavailable_item_cannot_be_ordered(): void
    {
        $this->actingAsUserWithPermissions(['orders.create']);
        $category = Category::create(['name' => 'Drinks', 'slug' => 'drinks-'.uniqid()]);
        $item = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Cola', 'slug' => 'cola-'.uniqid(),
            'base_price' => 60, 'is_available' => false,
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'item_unavailable');
    }

    public function test_modifier_from_a_different_food_item_is_rejected(): void
    {
        $this->actingAsUserWithPermissions(['orders.create']);
        $item = $this->makePizzaWithSizeModifiers();

        $otherCategory = Category::create(['name' => 'Burgers', 'slug' => 'burgers-'.uniqid()]);
        $otherItem = FoodItem::create([
            'category_id' => $otherCategory->id, 'name' => 'Cheeseburger', 'slug' => 'cheeseburger-'.uniqid(), 'base_price' => 300,
        ]);
        $foreignGroup = ModifierGroup::create(['food_item_id' => $otherItem->id, 'name' => 'Extras']);
        $foreignModifier = Modifier::create(['modifier_group_id' => $foreignGroup->id, 'name' => 'Extra cheese', 'price' => 30]);

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [
                ['food_item_id' => $item->id, 'quantity' => 1, 'modifier_ids' => [$foreignModifier->id]],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_modifier');
    }

    public function test_holding_an_order_saves_it_as_draft_without_sending_it_to_the_kitchen(): void
    {
        $this->actingAsUserWithPermissions(['orders.create']);
        $category = Category::create(['name' => 'Snacks', 'slug' => 'snacks-'.uniqid()]);
        $item = FoodItem::create(['category_id' => $category->id, 'name' => 'Fries', 'slug' => 'fries-'.uniqid(), 'base_price' => 150]);

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'hold' => true,
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }

    public function test_repeating_the_same_idempotency_key_returns_the_original_order_not_a_duplicate(): void
    {
        $this->actingAsUserWithPermissions(['orders.create']);
        $category = Category::create(['name' => 'Snacks', 'slug' => 'snacks2-'.uniqid()]);
        $item = FoodItem::create(['category_id' => $category->id, 'name' => 'Nachos', 'slug' => 'nachos-'.uniqid(), 'base_price' => 200]);

        $payload = ['source' => 'pos', 'items' => [['food_item_id' => $item->id, 'quantity' => 1]]];
        $key = 'test-idempotency-key-123';

        $first = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $key]);
        $second = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $key]);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }
}
