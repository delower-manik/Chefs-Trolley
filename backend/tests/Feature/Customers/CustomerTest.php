<?php

namespace Tests\Feature\Customers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\FoodItem;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    public function test_a_customer_can_be_created_and_found_by_search(): void
    {
        $this->actingAsUserWithPermissions(['customers.view', 'customers.manage']);

        $this->postJson('/api/v1/customers', [
            'name' => 'Rahim Uddin', 'phone' => '01711'.rand(100000, 999999),
        ])->assertStatus(201)->assertJsonPath('data.loyaltyPoints', 0);

        $response = $this->getJson('/api/v1/customers?search=Rahim');
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rahim Uddin');
    }

    public function test_two_customers_cannot_share_a_phone_number(): void
    {
        $this->actingAsUserWithPermissions(['customers.view', 'customers.manage']);
        $phone = '01799'.rand(100000, 999999);
        Customer::create(['name' => 'First', 'phone' => $phone]);

        $this->postJson('/api/v1/customers', ['name' => 'Second', 'phone' => $phone])
            ->assertStatus(422);
    }

    public function test_viewing_a_customers_order_history(): void
    {
        $user = $this->actingAsUserWithPermissions(['customers.view', 'orders.create', 'orders.view']);
        $customer = Customer::create(['name' => 'Karim', 'phone' => '01755'.rand(100000, 999999)]);

        $category = Category::create(['name' => 'Snacks', 'slug' => 'snacks-'.uniqid()]);
        $item = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Samosa', 'slug' => 'samosa-'.uniqid(), 'base_price' => 20,
        ]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'customer_id' => $customer->id,
            'items' => [['food_item_id' => $item->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $response = $this->getJson("/api/v1/customers/{$customer->id}/orders");
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.grandTotal', 40);
    }
}
