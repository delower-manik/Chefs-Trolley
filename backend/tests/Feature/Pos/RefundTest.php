<?php

namespace Tests\Feature\Pos;

use App\Models\CashShift;
use App\Models\IngredientStock;
use App\Models\Order;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use TakesPayments;

    private function paidOrder(array $extra = ['orders.refund']): array
    {
        [$user, $order, $dish] = $this->cashierWithOrder($extra);
        $this->openShift();
        $card = $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 100])->json('data.id');
        $cash = $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'amount' => 100])->json('data.id');

        return [$user, $order, $dish, $card, $cash];
    }

    public function test_partial_refund_updates_state_and_requires_a_reason(): void
    {
        [, $order, , $card] = $this->paidOrder();

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 30, 'refunds_payment_id' => $card])->assertStatus(422);
        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 30, 'refunds_payment_id' => $card, 'reason' => 'Cold food'])
            ->assertStatus(201)->assertJsonPath('summary.paymentState', 'partially_refunded')->assertJsonPath('summary.net', 170);

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 80, 'refunds_payment_id' => $card, 'reason' => 'Too much'])
            ->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_payment');
    }

    public function test_full_refund_with_restock_puts_ingredients_back_once(): void
    {
        [$user, $order, $dish, $card, $cash] = $this->paidOrder();
        // Rebuild with a recipe so stock is consumed.
        $dish2 = $this->makeFoodItem($user->active_branch_id, 50);
        $ingredient = $this->giveRecipe($dish2, $user->active_branch_id, 2, 10);
        $o2 = $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['food_item_id' => $dish2->id, 'quantity' => 3]]])->json('data.id');
        $this->postJson("/api/v1/orders/{$o2}/payments", ['method' => 'card'])->assertStatus(201);
        $this->assertEquals(4, IngredientStock::where('ingredient_id', $ingredient->id)->value('quantity'));

        $this->postJson("/api/v1/orders/{$o2}/refunds", ['amount' => 150, 'method' => 'card', 'reason' => 'Order mix-up', 'restock' => true])
            ->assertStatus(201)->assertJsonPath('summary.paymentState', 'refunded');

        $this->assertEquals(10, IngredientStock::where('ingredient_id', $ingredient->id)->value('quantity'));
        $this->assertSame('refunded', Order::find($o2)->status);
        $this->assertNotNull(Order::find($o2)->stock_restocked_at);
    }

    public function test_restock_needs_a_full_refund(): void
    {
        [, $order, , $card] = $this->paidOrder();

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 10, 'refunds_payment_id' => $card, 'reason' => 'Oops', 'restock' => true])
            ->assertStatus(422)->assertJsonPath('code', 'restock_requires_full_refund');
    }

    public function test_refund_needs_the_refund_permission_and_cannot_exceed_net_paid(): void
    {
        [, $order] = $this->paidOrder([]);
        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 10, 'method' => 'card', 'reason' => 'No'])->assertStatus(403);

        $this->actingAsUserWithPermissions(array_merge($this->posPerms, ['orders.refund']), $order->branch);
        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 500, 'method' => 'card', 'reason' => 'Too much'])
            ->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_paid');
    }

    public function test_voiding_a_payment_reopens_the_balance_and_is_audited(): void
    {
        [, $order, , $card] = $this->paidOrder();

        $this->postJson("/api/v1/payments/{$card}/void", ['reason' => 'Wrong card'])
            ->assertOk()->assertJsonPath('summary.balanceDue', 100)->assertJsonPath('summary.paymentState', 'unpaid');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.voided', 'subject_id' => $card]);
        $this->postJson("/api/v1/payments/{$card}/void", ['reason' => 'Again'])->assertStatus(422)->assertJsonPath('code', 'not_voidable');
    }

    public function test_a_cash_payment_cannot_be_voided_after_its_shift_closed(): void
    {
        [, $order, , , $cash] = $this->paidOrder();
        $shift = CashShift::first();
        $this->postJson("/api/v1/cash/shifts/{$shift->id}/close", ['counted_cash' => 600])->assertOk();

        $this->postJson("/api/v1/payments/{$cash}/void", ['reason' => 'Late'])->assertStatus(422)->assertJsonPath('code', 'shift_closed');
    }

    public function test_cash_refund_needs_an_open_shift(): void
    {
        [, $order] = $this->paidOrder();
        CashShift::query()->update(['status' => 'closed']);

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 10, 'method' => 'cash', 'reason' => 'Goodwill'])
            ->assertStatus(422)->assertJsonPath('code', 'no_open_shift');
    }
}
