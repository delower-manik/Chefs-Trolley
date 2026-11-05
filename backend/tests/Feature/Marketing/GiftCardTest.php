<?php

namespace Tests\Feature\Marketing;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\GiftCard;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class GiftCardTest extends TestCase
{
    use TakesPayments;

    private array $gcPerms = ['giftcards.view', 'giftcards.issue', 'giftcards.manage', 'orders.refund'];

    private function issue(float $value = 500, string $method = 'card'): string
    {
        return $this->postJson('/api/v1/gift-cards', ['value' => $value, 'sold_method' => $method])->assertStatus(201)->json('code');
    }

    public function test_issuing_returns_the_code_once_and_stores_only_a_hash(): void
    {
        [$user] = $this->cashierWithOrder($this->gcPerms);

        $res = $this->postJson('/api/v1/gift-cards', ['value' => 500, 'sold_method' => 'card', 'recipient_name' => 'Ayesha'])->assertStatus(201);
        $code = $res->json('code');
        $this->assertSame(16, strlen($code));
        $this->assertStringNotContainsString($code, $res->getContent() === '' ? '' : json_encode($res->json('data')));

        $card = GiftCard::firstOrFail();
        $this->assertNotSame($code, $card->code_hash);
        $this->assertSame(hash('sha256', $code), $card->code_hash);
        $this->assertSame(substr($code, -4), $card->code_last4);
        $this->assertSame('500.00', $card->balance);

        // The list never exposes the code.
        $this->assertStringNotContainsString($code, $this->getJson('/api/v1/gift-cards')->assertOk()->getContent());
        $this->assertSame(500, $this->getJson('/api/v1/gift-cards')->json('meta.outstandingBalance'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'giftcard.issued', 'actor_id' => $user->id]);
    }

    public function test_selling_for_cash_needs_a_shift_and_goes_into_the_drawer(): void
    {
        $this->cashierWithOrder($this->gcPerms);

        $this->postJson('/api/v1/gift-cards', ['value' => 300, 'sold_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'no_open_shift');
        $this->assertSame(0, GiftCard::count());

        $shift = $this->openShift(500);
        $this->issue(300, 'cash');
        $this->getJson("/api/v1/cash/shifts/{$shift}/report")->assertOk()->assertJsonPath('data.expectedNow', 800);
    }

    public function test_a_gift_card_pays_part_of_an_order_and_the_rest_is_paid_another_way(): void
    {
        [, $order] = $this->cashierWithOrder($this->gcPerms); // 200
        $code = $this->issue(150);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $code])
            ->assertStatus(201)->assertJsonPath('data.amount', 150)->assertJsonPath('summary.balanceDue', 50);

        $card = GiftCard::firstOrFail();
        $this->assertSame('0.00', $card->balance);
        $this->assertSame(['issue', 'redeem'], $card->entries->pluck('kind')->all());

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card', 'amount' => 50])
            ->assertStatus(201)->assertJsonPath('summary.paymentState', 'paid');

        // An empty card can't be spent again (a fresh order is needed, so place another on the same branch).
        $dish = $this->makeFoodItem($order->branch_id, 100);
        $id2 = $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/orders/{$id2}/payments", ['method' => 'gift_card', 'gift_card_code' => $code])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_invalid');
    }

    public function test_code_is_matched_ignoring_case_and_spaces_and_unknown_codes_are_rejected(): void
    {
        [, $order] = $this->cashierWithOrder($this->gcPerms);
        $code = $this->issue(1000);
        $pretty = strtolower(substr($code, 0, 4).' '.substr($code, 4, 4).'-'.substr($code, 8));

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => 'ZZZZZZZZZZZZZZZZ'])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_invalid');
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $pretty, 'amount' => 80])
            ->assertStatus(201);
        $this->assertSame('920.00', GiftCard::firstOrFail()->balance);
    }

    public function test_amount_above_the_card_balance_tips_and_expired_or_cancelled_cards_are_refused(): void
    {
        [, $order] = $this->cashierWithOrder($this->gcPerms);
        $code = $this->issue(100);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $code, 'amount' => 150])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_insufficient');
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $code, 'amount' => 50, 'tip' => 10])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_no_tip');
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card'])->assertStatus(422); // code required

        GiftCard::query()->update(['expires_at' => now()->subDay()]);
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $code, 'amount' => 50])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_invalid');
        $this->getJson('/api/v1/gift-cards?status=expired')->assertJsonCount(1, 'data');

        GiftCard::query()->update(['expires_at' => null]);
        $id = GiftCard::firstOrFail()->id;
        $this->postJson("/api/v1/gift-cards/{$id}/void", ['reason' => 'Lost by customer'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $code, 'amount' => 50])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_invalid');
        $this->assertSame('0.00', GiftCard::firstOrFail()->balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'giftcard.voided']);
    }

    public function test_voiding_a_gift_card_payment_puts_the_money_back_on_the_card(): void
    {
        [, $order] = $this->cashierWithOrder($this->gcPerms);
        $code = $this->issue(500);

        $paymentId = $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $code])
            ->assertStatus(201)->json('data.id');
        $this->assertSame('300.00', GiftCard::firstOrFail()->balance);

        $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'Wrong order'])->assertOk();
        $this->assertSame('500.00', GiftCard::firstOrFail()->balance);
        $this->assertSame(['issue', 'redeem', 'refund'], GiftCard::firstOrFail()->entries->pluck('kind')->all());
    }

    public function test_refunding_a_gift_card_payment_credits_the_same_card_and_needs_the_source_payment(): void
    {
        [, $order] = $this->cashierWithOrder($this->gcPerms);
        $code = $this->issue(500);
        $paymentId = $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'gift_card', 'gift_card_code' => $code])->json('data.id');

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 50, 'reason' => 'Cold food', 'method' => 'gift_card'])
            ->assertStatus(422)->assertJsonPath('code', 'gift_card_needs_payment');

        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 50, 'reason' => 'Cold food', 'refunds_payment_id' => $paymentId])->assertStatus(201);
        $this->assertSame('350.00', GiftCard::firstOrFail()->balance);
    }

    public function test_adjusting_is_audited_and_cannot_go_below_zero(): void
    {
        $this->cashierWithOrder($this->gcPerms);
        $this->issue(100);
        $id = GiftCard::firstOrFail()->id;

        $this->postJson("/api/v1/gift-cards/{$id}/adjust", ['amount' => -150, 'reason' => 'Typo'])->assertStatus(422)->assertJsonPath('code', 'below_zero');
        $this->postJson("/api/v1/gift-cards/{$id}/adjust", ['amount' => 50, 'reason' => 'Goodwill'])->assertOk()->assertJsonPath('data.balance', 150);
        $this->postJson("/api/v1/gift-cards/{$id}/adjust", ['amount' => 50])->assertStatus(422); // reason required
        $this->assertSame(1, AuditLog::where('action', 'giftcard.adjusted')->count());
        $this->getJson("/api/v1/gift-cards/{$id}")->assertOk()->assertJsonCount(2, 'data.entries');
    }

    public function test_lookup_reports_balance_and_permissions_are_enforced(): void
    {
        $this->cashierWithOrder($this->gcPerms);
        $code = $this->issue(250);

        $this->postJson('/api/v1/gift-cards/lookup', ['code' => $code])->assertOk()->assertJsonPath('data.balance', 250)->assertJsonPath('data.usable', true);
        $this->postJson('/api/v1/gift-cards/lookup', ['code' => 'NOPE'])->assertStatus(404);

        // A cashier without issue/manage rights can neither sell, adjust nor cancel cards.
        $this->actingAsUserWithPermissions(['pos.access', 'giftcards.view'], Branch::first());
        $this->postJson('/api/v1/gift-cards', ['value' => 10, 'sold_method' => 'card'])->assertStatus(403);
        $id = GiftCard::firstOrFail()->id;
        $this->postJson("/api/v1/gift-cards/{$id}/void", ['reason' => 'x y z'])->assertStatus(403);
        $this->postJson("/api/v1/gift-cards/{$id}/adjust", ['amount' => 5, 'reason' => 'x y z'])->assertStatus(403);
    }
}
