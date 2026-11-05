<?php

namespace Tests\Feature\Kitchen;

use App\Models\Order;
use Tests\TestCase;

class KitchenTicketTest extends TestCase
{
    public function test_ticket_board_excludes_draft_and_cancelled_orders(): void
    {
        $user = $this->actingAsUserWithPermissions(['kds.access']);

        Order::create(['code' => 'CT-1', 'source' => 'pos', 'branch_id' => $user->active_branch_id, 'status' => 'draft', 'kitchen_state' => 'new']);
        Order::create(['code' => 'CT-2', 'source' => 'pos', 'branch_id' => $user->active_branch_id, 'status' => 'cancelled', 'kitchen_state' => 'new']);
        Order::create(['code' => 'CT-3', 'source' => 'pos', 'branch_id' => $user->active_branch_id, 'status' => 'pending', 'kitchen_state' => 'new']);

        $response = $this->getJson('/api/v1/kitchen/tickets');

        $response->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'CT-3');
    }

    public function test_bumping_a_ticket_advances_its_kitchen_state(): void
    {
        $user = $this->actingAsUserWithPermissions(['kds.access']);
        $order = Order::create([
            'code' => 'CT-10', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'status' => 'pending', 'kitchen_state' => 'new',
        ]);

        $response = $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump");

        $response->assertStatus(200)->assertJsonPath('data.kitchenState', 'accepted');
    }

    public function test_bumping_to_ready_also_updates_the_order_status(): void
    {
        $user = $this->actingAsUserWithPermissions(['kds.access']);
        $order = Order::create([
            'code' => 'CT-11', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'status' => 'preparing', 'kitchen_state' => 'preparing',
        ]);

        $response = $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump");

        $response->assertStatus(200)
            ->assertJsonPath('data.kitchenState', 'ready')
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_completed_ticket_cannot_be_bumped_further(): void
    {
        $user = $this->actingAsUserWithPermissions(['kds.access']);
        $order = Order::create([
            'code' => 'CT-12', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'status' => 'completed', 'kitchen_state' => 'completed',
        ]);

        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/bump")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_kitchen_state');
    }

    public function test_reopen_moves_a_ticket_one_step_backward(): void
    {
        $user = $this->actingAsUserWithPermissions(['kds.access']);
        $order = Order::create([
            'code' => 'CT-13', 'source' => 'pos', 'branch_id' => $user->active_branch_id,
            'status' => 'preparing', 'kitchen_state' => 'preparing',
        ]);

        $this->postJson("/api/v1/kitchen/tickets/{$order->id}/reopen")
            ->assertStatus(200)
            ->assertJsonPath('data.kitchenState', 'accepted');
    }
}
