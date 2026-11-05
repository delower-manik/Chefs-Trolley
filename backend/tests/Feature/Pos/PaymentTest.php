<?php

namespace Tests\Feature\Pos;

use App\Models\Payment;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use TakesPayments;

    public function test_split_tender_marks_the_order_paid_only_when_fully_covered(): void
    {
        [, $order] = $this->cashierWithOrder(); // 200
        $this->openShift();

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 120, 'reference' => 'AUTH1'])
            ->assertStatus(201)->assertJsonPath('summary.balanceDue', 80)->assertJsonPath('summary.paymentState', 'unpaid');

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'amount' => 80, 'tendered' => 100])
            ->assertStatus(201)->assertJsonPath('data.changeGiven', 20)->assertJsonPath('summary.paymentState', 'paid')
            ->assertJsonPath('summary.balanceDue', 0);

        $this->assertSame('paid', $order->fresh()->payment_state);
    }

    public function test_amount_defaults_to_the_balance_and_overpayment_is_refused(): void
    {
        [, $order] = $this->cashierWithOrder();

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 250])
            ->assertStatus(422)->assertJsonPath('code', 'overpayment');

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card'])
            ->assertStatus(201)->assertJsonPath('data.amount', 200);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'already_paid');
    }

    public function test_cash_needs_an_open_shift_and_enough_tendered(): void
    {
        [, $order] = $this->cashierWithOrder();

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'no_open_shift');

        $this->openShift();
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'tendered' => 150])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_tender');
    }

    public function test_tips_are_separate_from_the_order_total_and_count_towards_change(): void
    {
        [, $order] = $this->cashierWithOrder();
        $this->openShift();

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'tip' => 20, 'tendered' => 250])
            ->assertStatus(201)->assertJsonPath('data.tip', 20)->assertJsonPath('data.changeGiven', 30)
            ->assertJsonPath('summary.tips', 20)->assertJsonPath('summary.paymentState', 'paid');

        $this->assertSame('200.00', $order->fresh()->grand_total);
    }

    public function test_replaying_an_idempotency_key_does_not_charge_twice(): void
    {
        [, $order] = $this->cashierWithOrder();
        $headers = ['Idempotency-Key' => 'pay-1'];

        $first = $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 50], $headers)->assertStatus(201)->json('data.id');
        $second = $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 50], $headers)->assertStatus(200)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_cancelled_order_cannot_be_paid(): void
    {
        [, $order] = $this->cashierWithOrder();
        $order->update(['status' => 'cancelled']);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card'])
            ->assertStatus(422)->assertJsonPath('code', 'order_not_payable');
    }

    public function test_taking_payments_needs_pos_access_and_branch_access(): void
    {
        [, $order] = $this->cashierWithOrder();

        $this->actingAsUserWithPermissions(['orders.view', 'orders.create'], $order->branch); // waiter: no pos.access (own branch)
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card'])->assertStatus(403);

        $this->actingAsUserWithPermissions($this->posPerms, $this->createBranch()); // other branch
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card'])->assertStatus(404);
    }

    public function test_order_api_reports_amount_paid_and_balance_due(): void
    {
        [, $order] = $this->cashierWithOrder();
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 75])->assertStatus(201);

        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.amountPaid', 75)->assertJsonPath('data.balanceDue', 125);
    }

    public function test_adding_a_round_to_a_paid_tab_reopens_the_balance(): void
    {
        [, $order, $dish] = $this->cashierWithOrder();
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card'])->assertStatus(201)->assertJsonPath('summary.paymentState', 'paid');

        $this->postJson("/api/v1/orders/{$order->id}/items", ['items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->assertOk();

        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.paymentState', 'unpaid')->assertJsonPath('data.balanceDue', 100);
    }

    public function test_an_order_with_payments_cannot_be_cancelled_until_refunded(): void
    {
        [, $order] = $this->cashierWithOrder(['orders.cancel']);
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 10])->assertStatus(201);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Changed mind'])
            ->assertStatus(422)->assertJsonPath('code', 'order_has_payments');
    }
}
