<?php

namespace Tests\Feature\Loyalty;

use App\Models\Category;
use App\Models\Customer;
use App\Models\FoodItem;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    public function test_placing_an_order_earns_the_customer_loyalty_points(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $customer = Customer::create(['name' => 'Points Earner', 'phone' => '01712'.rand(100000, 999999)]);

        $category = Category::create(['name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $item = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Kacchi', 'slug' => 'kacchi-'.uniqid(), 'base_price' => 350,
        ]);

        // grand total 350 -> floor(350/100) = 3 points
        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'customer_id' => $customer->id,
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.loyaltyPointsEarned', 3);
        $this->assertEquals(3, $customer->fresh()->loyalty_points);
    }

    public function test_a_held_order_does_not_earn_points_yet(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $customer = Customer::create(['name' => 'Holder', 'phone' => '01713'.rand(100000, 999999)]);
        $category = Category::create(['name' => 'Mains', 'slug' => 'mains2-'.uniqid()]);
        $item = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Kacchi', 'slug' => 'kacchi2-'.uniqid(), 'base_price' => 350,
        ]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'customer_id' => $customer->id, 'hold' => true,
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.loyaltyPointsEarned', 0);

        $this->assertEquals(0, $customer->fresh()->loyalty_points);
    }

    public function test_a_manual_loyalty_adjustment_is_reasoned_and_audited(): void
    {
        $this->actingAsUserWithPermissions(['customers.view', 'loyalty.manage']);
        $customer = Customer::create(['name' => 'Adjustable', 'phone' => '01714'.rand(100000, 999999)]);

        $this->postJson("/api/v1/customers/{$customer->id}/loyalty/adjust", ['points_change' => -1000])
            ->assertStatus(422); // missing reason

        $this->postJson("/api/v1/customers/{$customer->id}/loyalty/adjust", [
            'points_change' => 50, 'reason' => 'Birthday goodwill bonus',
        ])->assertStatus(200)->assertJsonPath('data.loyaltyPoints', 50);

        $ledger = $this->getJson("/api/v1/customers/{$customer->id}/loyalty/ledger");
        $ledger->assertJsonPath('data.0.type', 'adjusted')->assertJsonPath('data.0.pointsChange', 50);
    }
}
