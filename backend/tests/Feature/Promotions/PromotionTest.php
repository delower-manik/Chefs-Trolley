<?php

namespace Tests\Feature\Promotions;

use App\Models\Category;
use App\Models\Customer;
use App\Models\FoodItem;
use App\Models\Promotion;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    private function makeFoodItem(float $price = 500): FoodItem
    {
        $category = Category::create(['name' => 'Combo', 'slug' => 'combo-'.uniqid()]);

        return FoodItem::create([
            'category_id' => $category->id, 'name' => 'Set Meal', 'slug' => 'set-meal-'.uniqid(), 'base_price' => $price,
        ]);
    }

    public function test_a_percentage_promo_code_discounts_the_order_and_is_recorded(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'promotions.manage']);
        $item = $this->makeFoodItem(500);
        Promotion::create(['code' => 'save10', 'name' => '10% Off', 'type' => 'percentage', 'value' => 10]);

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'SAVE10', // codes are case-insensitive
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.discountTotal', 50) // 10% of 500
            ->assertJsonPath('data.grandTotal', 450)
            ->assertJsonPath('data.appliedPromoCode', 'SAVE10');

        $this->assertEquals(1, Promotion::first()->used_count);
    }

    public function test_a_percentage_promo_respects_a_max_discount_cap(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $item = $this->makeFoodItem(1000);
        Promotion::create([
            'code' => 'BIGSALE', 'name' => 'Big Sale', 'type' => 'percentage', 'value' => 50, 'max_discount_amount' => 100,
        ]);

        // 50% of 1000 would be 500, but the cap limits it to 100.
        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'BIGSALE',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.discountTotal', 100);
    }

    public function test_a_promo_code_below_its_minimum_order_amount_is_rejected(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $item = $this->makeFoodItem(100);
        Promotion::create(['code' => 'BIGSPEND', 'name' => 'Big Spend', 'type' => 'fixed', 'value' => 50, 'min_order_amount' => 1000]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'BIGSPEND',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'promo_minimum_not_met');
    }

    public function test_an_expired_promo_code_is_rejected(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $item = $this->makeFoodItem();
        Promotion::create(['code' => 'OLD', 'name' => 'Old Deal', 'type' => 'fixed', 'value' => 10, 'expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'OLD',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'promo_code_expired');
    }

    public function test_a_promo_code_stops_working_once_its_usage_limit_is_hit(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $item = $this->makeFoodItem();
        Promotion::create(['code' => 'ONLYONE', 'name' => 'Only One', 'type' => 'fixed', 'value' => 10, 'usage_limit' => 1]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'ONLYONE',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'ONLYONE',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'promo_code_exhausted');
    }

    public function test_a_per_customer_limit_requires_a_customer_and_is_enforced(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $item = $this->makeFoodItem();
        $customer = Customer::create(['name' => 'Loyal Customer', 'phone' => '01766'.rand(100000, 999999)]);
        Promotion::create([
            'code' => 'WELCOME', 'name' => 'Welcome', 'type' => 'fixed', 'value' => 20, 'usage_limit_per_customer' => 1,
        ]);

        // No customer attached -> rejected, since the limit can't be tracked.
        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'WELCOME',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'promo_requires_customer');

        // With a customer, first use succeeds...
        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'WELCOME', 'customer_id' => $customer->id,
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(201);

        // ...second use by the same customer is rejected.
        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'WELCOME', 'customer_id' => $customer->id,
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'promo_customer_limit_reached');
    }

    public function test_an_inactive_promo_code_is_rejected(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $item = $this->makeFoodItem();
        Promotion::create(['code' => 'PAUSED', 'name' => 'Paused', 'type' => 'fixed', 'value' => 10, 'is_active' => false]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'promo_code' => 'PAUSED',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_promo_code');
    }
}
