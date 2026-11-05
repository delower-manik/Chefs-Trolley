<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    public function test_user_without_permission_cannot_view_orders(): void
    {
        $this->actingAsUserWithPermissions([]); // no orders.view

        $this->getJson('/api/v1/orders')->assertStatus(403);
    }

    public function test_user_with_permission_can_list_orders_scoped_to_their_branch(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.view']);
        $otherBranch = $this->createBranch();

        Order::create([
            'code' => 'CT-1001', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'grand_total' => 500, 'status' => 'pending',
        ]);
        Order::create([
            'code' => 'CT-9999', 'source' => 'pos', 'branch_id' => $otherBranch->id,
            'grand_total' => 999, 'status' => 'pending',
        ]);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'CT-1001');
    }

    public function test_cancelling_a_preparing_order_succeeds_and_is_audited(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.view', 'orders.cancel']);
        $order = Order::create([
            'code' => 'CT-1002', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'grand_total' => 500, 'status' => 'preparing',
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Customer changed mind']);

        $response->assertStatus(200)->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.cancelled', 'subject_id' => $order->id]);

        // Regression: these three columns were once silently discarded by
        // mass-assignment protection, so a cancel lost its reason/actor/time.
        $fresh = $order->fresh();
        $this->assertEquals('Customer changed mind', $fresh->cancellation_reason);
        $this->assertEquals($user->id, $fresh->cancelled_by);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_cancelling_an_already_completed_order_is_rejected(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.view', 'orders.cancel']);
        $order = Order::create([
            'code' => 'CT-1003', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'grand_total' => 500, 'status' => 'completed',
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_order_state');
    }

    public function test_user_without_cancel_permission_gets_forbidden(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.view']); // no orders.cancel
        $order = Order::create([
            'code' => 'CT-1004', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'grand_total' => 500, 'status' => 'pending',
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(403);
    }
}
