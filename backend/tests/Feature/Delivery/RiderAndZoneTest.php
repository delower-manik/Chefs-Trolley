<?php

namespace Tests\Feature\Delivery;

use App\Models\Category;
use App\Models\FoodItem;
use App\Models\Rider;
use Tests\TestCase;

class RiderAndZoneTest extends TestCase
{
    public function test_a_rider_can_be_created_and_two_riders_cannot_share_a_phone(): void
    {
        $this->actingAsUserWithPermissions(['delivery.manage']);
        $phone = '017'.rand(10000000, 99999999);

        $this->postJson('/api/v1/riders', ['name' => 'Rider One', 'phone' => $phone])
            ->assertStatus(201)->assertJsonPath('data.status', 'offline');

        $this->postJson('/api/v1/riders', ['name' => 'Rider Two', 'phone' => $phone])->assertStatus(422);
    }

    public function test_a_riders_status_can_be_toggled_with_a_partial_update(): void
    {
        $user = $this->actingAsUserWithPermissions(['delivery.manage']);
        $rider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider', 'phone' => '018'.rand(10000000, 99999999)]);

        // A partial payload (just the field being toggled) must not 422 --
        // this is the same class of bug fixed on SaveTableRequest/
        // SaveIngredientRequest in earlier phases.
        $this->putJson("/api/v1/riders/{$rider->id}", ['status' => 'available'])
            ->assertStatus(200)->assertJsonPath('data.status', 'available');
    }

    public function test_a_rider_with_an_active_delivery_cannot_be_deleted(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'delivery.manage']);
        $rider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Busy Rider', 'phone' => '019'.rand(10000000, 99999999)]);

        $category = Category::create(['name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $item = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Burger', 'slug' => 'burger-'.uniqid(), 'base_price' => 200,
        ]);
        $order = $this->postJson('/api/v1/orders', [
            'source' => 'delivery', 'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->json('data');

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $rider->id])->assertStatus(200);

        $this->deleteJson("/api/v1/riders/{$rider->id}")
            ->assertStatus(422)->assertJsonPath('code', 'rider_has_active_deliveries');
    }

    public function test_a_delivery_zone_can_be_created_updated_and_deleted(): void
    {
        $this->actingAsUserWithPermissions(['delivery.manage']);

        $create = $this->postJson('/api/v1/delivery-zones', ['name' => 'Downtown', 'delivery_fee' => 40]);
        $create->assertStatus(201)->assertJsonPath('data.deliveryFee', 40);

        $zoneId = $create->json('data.id');

        // Partial update -- only the fee -- must not require re-sending name.
        $this->putJson("/api/v1/delivery-zones/{$zoneId}", ['delivery_fee' => 55])
            ->assertStatus(200)->assertJsonPath('data.deliveryFee', 55);

        $this->deleteJson("/api/v1/delivery-zones/{$zoneId}")->assertStatus(204);
    }

    public function test_two_zones_in_the_same_branch_cannot_share_a_name(): void
    {
        $this->actingAsUserWithPermissions(['delivery.manage']);

        $this->postJson('/api/v1/delivery-zones', ['name' => 'Zone A', 'delivery_fee' => 30])->assertStatus(201);
        $this->postJson('/api/v1/delivery-zones', ['name' => 'Zone A', 'delivery_fee' => 50])->assertStatus(422);
    }
}
