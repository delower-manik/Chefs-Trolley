<?php

namespace Tests\Feature\Delivery;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\FoodItem;
use App\Models\Rider;
use Tests\TestCase;

class DeliveryAssignmentTest extends TestCase
{
    private function placeDeliveryOrder(array $overrides = []): array
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'delivery.manage']);
        $category = Category::create(['name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $item = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Burger', 'slug' => 'burger-'.uniqid(), 'base_price' => 200,
        ]);

        $response = $this->postJson('/api/v1/orders', array_merge([
            'source' => 'delivery',
            'delivery_address' => '123 Main Road',
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ], $overrides));

        return ['user' => $user, 'order' => $response->json('data')];
    }

    public function test_a_delivery_order_gets_a_pending_assignment_automatically(): void
    {
        ['order' => $order] = $this->placeDeliveryOrder();

        $response = $this->getJson('/api/v1/deliveries');
        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.orderId', $order['id'])
            ->assertJsonPath('data.0.deliveryAddress', '123 Main Road');
    }

    public function test_the_full_assign_pickup_deliver_flow(): void
    {
        ['user' => $user, 'order' => $order] = $this->placeDeliveryOrder();
        $rider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Karim Rider', 'phone' => '017'.rand(10000000, 99999999)]);

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $rider->id])
            ->assertStatus(200)->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.riderId', $rider->id);

        $this->assertEquals('busy', $rider->fresh()->status);
        $this->assertEquals('assigned', $this->getJson("/api/v1/orders/{$order['id']}")->json('data.status'));

        $this->postJson("/api/v1/deliveries/{$order['id']}/pickup")
            ->assertStatus(200)->assertJsonPath('data.status', 'picked_up');
        $this->assertEquals('picked_up', $this->getJson("/api/v1/orders/{$order['id']}")->json('data.status'));

        $this->postJson("/api/v1/deliveries/{$order['id']}/deliver", [
            'recipient_name' => 'Jane Doe', 'proof_note' => 'Handed to customer at the door',
        ])->assertStatus(200)->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.recipientName', 'Jane Doe');

        $this->assertEquals('delivered', $this->getJson("/api/v1/orders/{$order['id']}")->json('data.status'));

        // The rider is freed once their only delivery finishes.
        $this->assertEquals('available', $rider->fresh()->status);
    }

    public function test_pickup_cannot_happen_before_a_rider_is_assigned(): void
    {
        ['order' => $order] = $this->placeDeliveryOrder();

        $this->postJson("/api/v1/deliveries/{$order['id']}/pickup")
            ->assertStatus(422)->assertJsonPath('code', 'invalid_delivery_state');
    }

    public function test_a_failed_delivery_frees_the_rider_and_can_be_reassigned(): void
    {
        ['user' => $user, 'order' => $order] = $this->placeDeliveryOrder();
        $rider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider One', 'phone' => '018'.rand(10000000, 99999999)]);

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $rider->id])->assertStatus(200);
        $this->postJson("/api/v1/deliveries/{$order['id']}/fail", ['reason' => 'Customer not reachable'])
            ->assertStatus(200)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failureReason', 'Customer not reachable');

        $this->assertEquals('available', $rider->fresh()->status);

        // Retrying with a second rider works from the failed state.
        $secondRider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider Two', 'phone' => '019'.rand(10000000, 99999999)]);
        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $secondRider->id])
            ->assertStatus(200)->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.riderId', $secondRider->id);
    }

    public function test_reassigning_frees_the_previous_rider(): void
    {
        ['user' => $user, 'order' => $order] = $this->placeDeliveryOrder();
        $riderA = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider A', 'phone' => '015'.rand(10000000, 99999999)]);
        $riderB = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider B', 'phone' => '016'.rand(10000000, 99999999)]);

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $riderA->id])->assertStatus(200);
        $this->assertEquals('busy', $riderA->fresh()->status);

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $riderB->id])
            ->assertStatus(200)->assertJsonPath('data.riderId', $riderB->id);

        $this->assertEquals('available', $riderA->fresh()->status, 'previous rider should be freed');
        $this->assertEquals('busy', $riderB->fresh()->status);
    }

    public function test_cannot_reassign_a_rider_once_picked_up(): void
    {
        ['user' => $user, 'order' => $order] = $this->placeDeliveryOrder();
        $rider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider One', 'phone' => '014'.rand(10000000, 99999999)]);

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $rider->id])->assertStatus(200);
        $this->postJson("/api/v1/deliveries/{$order['id']}/pickup")->assertStatus(200);

        $anotherRider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider Two', 'phone' => '013'.rand(10000000, 99999999)]);
        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $anotherRider->id])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_delivery_state');
    }

    public function test_an_inactive_rider_cannot_be_assigned(): void
    {
        ['user' => $user, 'order' => $order] = $this->placeDeliveryOrder();
        $rider = Rider::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Inactive Rider',
            'phone' => '012'.rand(10000000, 99999999), 'is_active' => false,
        ]);

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $rider->id])
            ->assertStatus(422)->assertJsonPath('code', 'rider_inactive');
    }

    public function test_a_non_delivery_order_cannot_be_assigned_a_rider(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'delivery.manage']);
        $category = Category::create(['name' => 'Snacks', 'slug' => 'snacks-'.uniqid()]);
        $item = FoodItem::create(['category_id' => $category->id, 'name' => 'Fries', 'slug' => 'fries-'.uniqid(), 'base_price' => 100]);

        $order = $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->json('data');

        $rider = Rider::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rider', 'phone' => '011'.rand(10000000, 99999999)]);

        $this->postJson("/api/v1/deliveries/{$order['id']}/assign", ['rider_id' => $rider->id])
            ->assertStatus(422)->assertJsonPath('code', 'not_a_delivery_order');
    }

    public function test_a_delivery_zone_overrides_the_client_supplied_delivery_fee(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'delivery.manage']);
        $zone = DeliveryZone::create(['branch_id' => $user->activeBranch->id, 'name' => 'Zone A', 'delivery_fee' => 60]);
        $category = Category::create(['name' => 'Mains', 'slug' => 'mains2-'.uniqid()]);
        $item = FoodItem::create(['category_id' => $category->id, 'name' => 'Pizza', 'slug' => 'pizza-'.uniqid(), 'base_price' => 400]);

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'delivery', 'delivery_zone_id' => $zone->id, 'delivery_fee' => 5, // client tries to lowball the fee
            'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.deliveryFee', 60)->assertJsonPath('data.grandTotal', 460);
    }
}
