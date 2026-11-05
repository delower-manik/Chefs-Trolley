<?php

namespace Tests\Feature\Pos;

use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use TakesPayments;

    public function test_cashier_can_discount_within_the_cap_with_a_reason(): void
    {
        [, $order] = $this->cashierWithOrder(); // 200, cap 20% = 40

        $this->putJson("/api/v1/orders/{$order->id}/discount", ['type' => 'percent', 'value' => 10, 'reason' => 'Regular customer'])
            ->assertOk()->assertJsonPath('data.discountTotal', 20)->assertJsonPath('data.grandTotal', 180);

        $this->assertDatabaseHas('audit_logs', ['action' => 'order.discount_applied', 'subject_id' => $order->id]);
    }

    public function test_over_the_cap_needs_override(): void
    {
        [, $order] = $this->cashierWithOrder();

        $this->putJson("/api/v1/orders/{$order->id}/discount", ['type' => 'percent', 'value' => 50, 'reason' => 'Complaint'])
            ->assertStatus(422)->assertJsonPath('code', 'discount_over_limit');

        $this->actingAsUserWithPermissions(array_merge($this->posPerms, ['discounts.override']), $order->branch);
        $this->putJson("/api/v1/orders/{$order->id}/discount", ['type' => 'percent', 'value' => 50, 'reason' => 'Complaint'])
            ->assertOk()->assertJsonPath('data.grandTotal', 100);
    }

    public function test_without_a_discount_permission_it_is_forbidden(): void
    {
        [, $order] = $this->cashierWithOrder();
        $this->actingAsUserWithPermissions(['orders.view', 'orders.create'], $order->branch);

        $this->putJson("/api/v1/orders/{$order->id}/discount", ['type' => 'fixed', 'value' => 5, 'reason' => 'Just because'])
            ->assertStatus(403)->assertJsonPath('code', 'discount_not_permitted');
    }

    public function test_discount_cannot_exceed_what_is_owed_even_with_override(): void
    {
        [, $order] = $this->cashierWithOrder(['discounts.override']);

        $this->putJson("/api/v1/orders/{$order->id}/discount", ['type' => 'fixed', 'value' => 500, 'reason' => 'Free meal'])
            ->assertStatus(422)->assertJsonPath('code', 'discount_exceeds_total');
    }

    public function test_discount_is_locked_once_payments_exist_and_can_be_removed_before(): void
    {
        [, $order] = $this->cashierWithOrder();
        $this->putJson("/api/v1/orders/{$order->id}/discount", ['type' => 'fixed', 'value' => 20, 'reason' => 'Loyalty'])->assertOk();
        $this->deleteJson("/api/v1/orders/{$order->id}/discount")->assertOk()->assertJsonPath('data.grandTotal', 200);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 10])->assertStatus(201);
        $this->putJson("/api/v1/orders/{$order->id}/discount", ['type' => 'fixed', 'value' => 20, 'reason' => 'Late'])
            ->assertStatus(422)->assertJsonPath('code', 'order_has_payments');
    }

    public function test_client_supplied_discount_at_checkout_is_permissioned_and_needs_a_reason(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.view', 'orders.create', 'pos.access']);
        $dish = $this->makeFoodItem($user->active_branch_id, 100);
        $payload = ['source' => 'pos', 'discount_total' => 10, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/orders', $payload)->assertStatus(403)->assertJsonPath('code', 'discount_not_permitted');

        $this->actingAsUserWithPermissions($this->posPerms, $user->activeBranch);
        $this->postJson('/api/v1/orders', $payload)->assertStatus(422)->assertJsonPath('code', 'discount_reason_required');
        $this->postJson('/api/v1/orders', $payload + ['discount_reason' => 'Staff meal'])
            ->assertStatus(201)->assertJsonPath('data.discountTotal', 10)->assertJsonPath('data.grandTotal', 90)
            ->assertJsonPath('data.manualDiscountTotal', 10);
    }
}
