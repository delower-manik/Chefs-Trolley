<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\GiftCard;
use App\Models\GiftCardEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AppSettings;
use App\Services\GiftCards;
use App\Services\OrderLedger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Payments are rows in a ledger (kind payment|refund). Split / partial tender is simply several
 * payment rows against one order; an order is "paid" when the net of the ledger covers grand_total.
 * Tips ride on the payment row and are NOT part of grand_total.
 */
class PaymentController extends Controller
{
    public function __construct(private OrderLedger $ledger, private GiftCards $giftCards) {}

    /** GET /orders/{order}/payments */
    public function index(Order $order)
    {
        Gate::authorize('view', $order);

        return response()->json([
            'data' => $order->payments()->orderBy('created_at')->get()->map(fn ($p) => $this->serialize($p)),
            'summary' => $this->summary($order),
        ]);
    }

    /** POST /orders/{order}/payments  {method, amount?, tip?, tendered?, reference?} */
    public function store(Request $request, Order $order)
    {
        Gate::authorize('view', $order);

        $data = $request->validate([
            'method' => ['required', 'in:'.implode(',', config('pos.payment_methods'))],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
            'tip' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'tendered' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'gift_card_code' => ['required_if:method,gift_card', 'nullable', 'string', 'max:40'],
        ]);

        $key = $request->header('Idempotency-Key');
        if ($key && ($existing = Payment::where('idempotency_key', $key)->where('branch_id', $order->branch_id)->first())) {
            return response()->json(['data' => $this->serialize($existing), 'summary' => $this->summary($order->fresh())]);
        }

        if (in_array($order->status, ['draft', 'cancelled', 'refunded'], true)) {
            return $this->error('order_not_payable', "Order {$order->code} can't take payments while it is {$order->status}.");
        }

        $result = DB::transaction(function () use ($request, $order, $data, $key) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            // A same-key retry that raced us has committed by now: answer with the original instead of failing.
            if ($key && ($dup = Payment::where('idempotency_key', $key)->where('branch_id', $order->branch_id)->first())) {
                return response()->json(['data' => $this->serialize($dup), 'summary' => $this->summary($order->fresh())]);
            }

            $balance = $this->ledger->balanceMinor($order);
            if ($balance <= 0 && Money::toMinor($order->grand_total) > 0) {
                return $this->error('already_paid', "Order {$order->code} is already fully paid.");
            }

            $card = null;
            if ($data['method'] === 'gift_card') {
                if (! app(AppSettings::class)->enabled('gift_cards')) {
                    return $this->error('feature_disabled', 'Gift cards have been turned off in Settings.');
                }
                $card = $this->giftCards->find($data['gift_card_code'], lock: true);
                if (! $card || ($problem = $this->giftCards->problem($card))) {
                    return $this->error('gift_card_invalid', $card ? $problem : 'That gift card code was not recognised.');
                }
                if (Money::toMinor($data['tip'] ?? 0) > 0) {
                    return $this->error('gift_card_no_tip', 'A tip cannot be paid from a gift card. Take the tip by another method.');
                }
                $data['reference'] = 'GC-'.$card->code_last4;
            }

            $amount = isset($data['amount']) ? Money::toMinor($data['amount']) : ($card ? min($balance, Money::toMinor($card->balance)) : $balance);
            if ($amount > $balance) {
                return $this->error('overpayment', 'This payment is more than the balance due. Record the extra as a tip instead.');
            }
            if ($card && $amount > Money::toMinor($card->balance)) {
                return $this->error('gift_card_insufficient', 'The gift card balance is '.number_format((float) $card->balance, 2).', which is less than this amount.');
            }
            $tip = Money::toMinor($data['tip'] ?? 0);

            $shiftId = null;
            $tendered = null;
            $change = null;
            if ($data['method'] === 'cash') {
                $shift = $this->openShift($request, $order->branch_id);
                if (! $shift) {
                    return $this->error('no_open_shift', 'Open a cash register shift before taking cash.');
                }
                $shiftId = $shift->id;
                $tendered = isset($data['tendered']) ? Money::toMinor($data['tendered']) : $amount + $tip;
                if ($tendered < $amount + $tip) {
                    return $this->error('insufficient_tender', 'The cash handed over is less than the amount due.');
                }
                $change = $tendered - ($amount + $tip);
            }

            $payment = Payment::create([
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'shift_id' => $shiftId,
                'kind' => 'payment', 'method' => $data['method'], 'reference' => $data['reference'] ?? null,
                'amount' => Money::toDecimal($amount), 'tip' => Money::toDecimal($tip),
                'tendered' => $tendered === null ? null : Money::toDecimal($tendered),
                'change_given' => $change === null ? null : Money::toDecimal($change),
                'idempotency_key' => $key, 'created_by' => $request->user()->id,
            ]);

            if ($card) {
                $this->giftCards->redeem($card, $amount, $order->id, $payment->id, $request->user()->id);
            }

            $this->ledger->refreshState($order);
            $this->recordAudit($request, 'payment.taken', $payment, [
                'order' => $order->code, 'method' => $payment->method, 'amount' => (float) $payment->amount, 'tip' => (float) $payment->tip,
                'gift_card' => $card ? 'GC-'.$card->code_last4 : null,
            ]);

            return $payment;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['data' => $this->serialize($result), 'summary' => $this->summary($order->fresh())], 201);
    }

    /** POST /orders/{order}/refunds  {amount, reason, method?, refunds_payment_id?, restock?} */
    public function refund(Request $request, Order $order)
    {
        Gate::authorize('view', $order);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'method' => ['nullable', 'in:'.implode(',', config('pos.payment_methods'))],
            'refunds_payment_id' => ['nullable', 'uuid'],
            'restock' => ['nullable', 'boolean'],
        ]);

        $result = DB::transaction(function () use ($request, $order, $data) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $amount = Money::toMinor($data['amount']);

            if ($amount > $this->ledger->netMinor($order)) {
                return $this->error('refund_exceeds_paid', 'You can refund at most what is currently paid on this order.');
            }

            $source = null;
            if (! empty($data['refunds_payment_id'])) {
                $source = Payment::where('id', $data['refunds_payment_id'])->where('order_id', $order->id)
                    ->where('kind', 'payment')->whereNull('voided_at')->first();
                if (! $source) {
                    return $this->error('invalid_payment', 'That payment does not belong to this order.');
                }
                $already = Payment::where('refunds_payment_id', $source->id)->whereNull('voided_at')->get()
                    ->sum(fn ($p) => Money::toMinor($p->amount));
                if ($amount > Money::toMinor($source->amount) - $already) {
                    return $this->error('refund_exceeds_payment', 'That is more than is left to refund on this payment.');
                }
            }
            $method = $source?->method ?? ($data['method'] ?? null);
            if (! $method) {
                return $this->error('method_required', 'Say which method the money goes back by.');
            }
            if ($method === 'gift_card' && ! $source) {
                return $this->error('gift_card_needs_payment', 'Refund a gift-card payment against that payment, so the money goes back onto the same card.');
            }

            $shiftId = null;
            if ($method === 'cash') {
                $shift = $this->openShift($request, $order->branch_id);
                if (! $shift) {
                    return $this->error('no_open_shift', 'Open a cash register shift before paying out a cash refund.');
                }
                $shiftId = $shift->id;
            }

            $fullRefund = $amount === $this->ledger->netMinor($order);
            $restock = (bool) ($data['restock'] ?? false);
            if ($restock && ! $fullRefund) {
                return $this->error('restock_requires_full_refund', 'Stock can only be put back when the whole order is refunded.');
            }

            $refund = Payment::create([
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'shift_id' => $shiftId, 'kind' => 'refund',
                'method' => $method, 'amount' => Money::toDecimal($amount), 'tip' => 0, 'reason' => $data['reason'],
                'refunds_payment_id' => $source?->id, 'restocked' => $restock, 'created_by' => $request->user()->id,
            ]);

            if ($method === 'gift_card') {
                $this->creditGiftCard($source, $amount, 'refund', "Refund on {$order->code}: {$data['reason']}", $request->user()->id);
            }

            $restocked = $restock ? $this->ledger->restock($order, $request->user()->id) : 0;
            $this->ledger->refreshState($order);
            if ($fullRefund && ! in_array($order->status, ['cancelled'], true)) {
                $order->update(['status' => 'refunded']);
                $order->diningTable?->releaseIfIdle();
            }

            $this->recordAudit($request, 'payment.refunded', $refund, [
                'order' => $order->code, 'amount' => (float) $refund->amount, 'reason' => $data['reason'],
                'full' => $fullRefund, 'restocked_lines' => $restocked,
            ]);

            return $refund;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['data' => $this->serialize($result), 'summary' => $this->summary($order->fresh())], 201);
    }

    /** POST /payments/{payment}/void  {reason} — undo a mistaken payment (cash: only while its shift is open). */
    public function void(Request $request, Payment $payment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $result = DB::transaction(function () use ($request, $payment, $data) {
            // Same lock order as every other money path (order first, then payment) so they cannot deadlock.
            $order = Order::lockForUpdate()->findOrFail(Payment::whereKey($payment->id)->value('order_id') ?? $payment->order_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            if ($payment->kind !== 'payment' || $payment->voided_at) {
                return $this->error('not_voidable', 'This payment cannot be voided.');
            }
            if (Payment::where('refunds_payment_id', $payment->id)->whereNull('voided_at')->exists()) {
                return $this->error('has_refunds', 'This payment already has refunds against it.');
            }
            if ($payment->shift_id && CashShift::find($payment->shift_id)?->status !== 'open') {
                return $this->error('shift_closed', "That payment's shift is closed. Refund it instead of voiding.");
            }

            $payment->update(['voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason']]);
            if ($payment->method === 'gift_card') {
                $this->creditGiftCard($payment, Money::toMinor($payment->amount), 'refund', "Payment voided on {$order->code}: {$data['reason']}", $request->user()->id);
            }
            $this->ledger->refreshState($order);
            $this->recordAudit($request, 'payment.voided', $payment, ['order' => $order->code, 'reason' => $data['reason']]);

            return [$payment, $order];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['data' => $this->serialize($result[0]), 'summary' => $this->summary($result[1]->fresh())]);
    }

    /** Put money back on the card a gift-card payment was taken from. */
    private function creditGiftCard(Payment $payment, int $amountMinor, string $kind, string $reason, ?string $userId): void
    {
        $entry = GiftCardEntry::where('payment_id', $payment->id)->where('kind', 'redeem')->first();
        $card = $entry ? GiftCard::lockForUpdate()->find($entry->gift_card_id) : null;
        if (! $card) {
            throw new \RuntimeException('The gift card for this payment could not be found.');
        }
        $this->giftCards->credit($card, $amountMinor, $kind, $payment->order_id, $payment->id, $reason, $userId);
    }

    private function openShift(Request $request, string $branchId): ?CashShift
    {
        return CashShift::where('branch_id', $branchId)->where('opened_by', $request->user()->id)->where('status', 'open')->first();
    }

    private function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    private function summary(Order $order): array
    {
        $grand = Money::toMinor($order->grand_total);
        $paid = $this->ledger->paidMinor($order);
        $refunded = $this->ledger->refundedMinor($order);

        return [
            'grandTotal' => Money::toFloat($grand),
            'paid' => Money::toFloat($paid),
            'refunded' => Money::toFloat($refunded),
            'net' => Money::toFloat($paid - $refunded),
            'balanceDue' => Money::toFloat($this->ledger->balanceMinor($order)),
            'tips' => Money::toFloat(Payment::where('order_id', $order->id)->where('kind', 'payment')->whereNull('voided_at')
                ->get(['tip'])->sum(fn ($p) => Money::toMinor($p->tip))),
            'paymentState' => $order->payment_state,
        ];
    }

    private function serialize(Payment $p): array
    {
        return [
            'id' => $p->id, 'orderId' => $p->order_id, 'kind' => $p->kind, 'method' => $p->method,
            'reference' => $p->reference, 'amount' => (float) $p->amount, 'tip' => (float) $p->tip,
            'tendered' => $p->tendered === null ? null : (float) $p->tendered,
            'changeGiven' => $p->change_given === null ? null : (float) $p->change_given,
            'reason' => $p->reason, 'refundsPaymentId' => $p->refunds_payment_id, 'restocked' => $p->restocked,
            'voidedAt' => $p->voided_at?->toIso8601String(), 'voidReason' => $p->void_reason,
            'createdAt' => $p->created_at?->toIso8601String(),
        ];
    }
}
