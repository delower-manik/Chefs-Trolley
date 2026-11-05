<?php

namespace App\Services\Gateways;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentSession;
use App\Services\OrderLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts online payments and turns a provider-confirmed result into a ledger payment. The only way an
 * order is ever credited from a gateway is settle(): after a server-to-server verification, with the
 * amount compared to what WE fixed at creation, and exactly once per session.
 */
class GatewayPayments
{
    public function __construct(private GatewayManager $gateways, private OrderLedger $ledger) {}

    /**
     * @return PaymentSession|string a session, or a human-readable reason it can't be started
     */
    public function start(Order $order, string $gatewayKey, ?int $amountMinor, ?string $userId, string $callbackBase): PaymentSession|string
    {
        $driver = $this->gateways->driver($gatewayKey);
        $config = $driver ? $this->gateways->usableConfig($gatewayKey) : null;
        if (! $driver || $config === null) {
            return 'That payment method is not set up. Ask a manager to enable it and add its credentials in Integrations → Payments.';
        }
        if (in_array($order->status, ['draft', 'cancelled', 'refunded'], true)) {
            return "Order {$order->code} can't take payments while it is {$order->status}.";
        }

        $balance = $this->ledger->balanceMinor($order);
        if ($balance <= 0) {
            return "Order {$order->code} is already fully paid.";
        }
        $amount = $amountMinor ?? $balance;
        if ($amount <= 0 || $amount > $balance) {
            return 'The amount must be more than zero and no more than the balance due.';
        }

        // Re-use a still-open session for the same order, gateway and amount instead of piling up duplicates.
        $existing = PaymentSession::where('order_id', $order->id)->where('gateway_key', $gatewayKey)->whereIn('status', ['created', 'pending'])
            ->where('amount', Money::toDecimal($amount))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest()->first();
        if ($existing && ($existing->redirect_url || $existing->qr_payload)) {
            return $existing;
        }

        $session = PaymentSession::create([
            'order_id' => $order->id, 'branch_id' => $order->branch_id, 'gateway_key' => $gatewayKey,
            'amount' => Money::toDecimal($amount), 'currency' => 'BDT', 'status' => 'created',
            'expires_at' => now()->addMinutes((int) config('gateways.session_minutes', 30)), 'created_by' => $userId,
        ]);

        $customer = $order->customer_id ? Customer::find($order->customer_id) : null;
        $info = [
            'name' => $customer?->name ?: 'Walk-in customer',
            'phone' => $customer?->phone ?: '01700000000',
            'email' => $customer?->email ?: 'customer@example.com',
            'invoice' => $order->code,
            'description' => "Order {$order->code}",
        ];

        try {
            $result = $driver->initiate($session, $config, rtrim($callbackBase, '/')."/payment-callbacks/{$gatewayKey}/{$session->id}/{$session->callbackToken()}", $info);
        } catch (\Throwable $e) {
            report($e);
            $result = InitResult::failed('The payment provider could not be reached.');
        }

        if (! $result->ok) {
            $session->update(['status' => 'failed', 'note' => Str::limit((string) $result->error, 250, '')]);

            return (string) $result->error ?: 'The payment provider refused to start this payment.';
        }

        $session->update([
            'status' => 'pending', 'provider_ref' => $result->providerRef, 'redirect_url' => $result->redirectUrl,
            'qr_payload' => $result->qrPayload, 'payload' => $result->payload ?: null,
        ]);

        return $session->fresh();
    }

    /** Ask the provider what happened and settle accordingly. Safe to call repeatedly (callback, IPN, "check now", cron). */
    public function refresh(PaymentSession $session, array $callbackData = [], ?string $actorId = null): PaymentSession
    {
        // Closed-but-unpaid sessions are still checked: a customer can pay just after we gave up, and that must surface.
        if (in_array($session->status, ['paid', 'review'], true)) {
            return $session;
        }
        $driver = $this->gateways->driver($session->gateway_key);
        $config = $this->gateways->usableConfig($session->gateway_key);
        if (! $driver || $config === null || $driver::manual()) {
            return $session;
        }

        try {
            $result = $driver->verify($session, $config, $callbackData);
        } catch (\Throwable $e) {
            report($e);

            return $session; // couldn't ask — leave it pending, the reconcile job tries again
        }

        return $this->apply($session, $result, $actorId);
    }

    /** A person confirmed a manual-method payment (Bangla QR) — recorded as unverified by the bank. */
    public function confirmManually(PaymentSession $session, string $trxId, string $actorId, string $actorName): PaymentSession
    {
        $result = VerifyResult::paid($trxId, Money::toMinor($session->amount));

        return $this->apply($session, $result, $actorId, "manually confirmed by {$actorName}");
    }

    public function cancel(PaymentSession $session): PaymentSession
    {
        PaymentSession::where('id', $session->id)->whereIn('status', ['created', 'pending'])->update(['status' => 'cancelled', 'note' => 'Cancelled at the till']);

        return $session->fresh();
    }

    public function expireOld(): int
    {
        return PaymentSession::whereIn('status', ['created', 'pending'])->where('expires_at', '<', now())->update(['status' => 'expired']);
    }

    private function apply(PaymentSession $session, VerifyResult $result, ?string $actorId, ?string $via = null): PaymentSession
    {
        if ($result->state === 'pending') {
            return $session;
        }
        if ($result->state === 'failed') {
            PaymentSession::where('id', $session->id)->whereIn('status', ['created', 'pending'])->update(['status' => 'failed', 'note' => Str::limit((string) $result->error, 250, '')]);

            return $session->fresh();
        }

        return DB::transaction(function () use ($session, $result, $actorId, $via) {
            $locked = PaymentSession::lockForUpdate()->findOrFail($session->id);
            // A late "paid" for a session we already closed is exactly the case a human must look at.
            if (! in_array($locked->status, ['created', 'pending', 'expired', 'cancelled', 'failed'], true)) {
                return $locked;
            }
            $order = Order::lockForUpdate()->findOrFail($locked->order_id);
            $expected = Money::toMinor($locked->amount);

            $problem = match (true) {
                $result->amountMinor !== null && $result->amountMinor !== $expected => 'Provider reports '.number_format($result->amountMinor / 100, 2).' but the session was for '.number_format($expected / 100, 2).'.',
                $result->currency !== null && strtoupper($result->currency) !== 'BDT' => "Provider reports currency {$result->currency}.",
                in_array($order->status, ['draft', 'cancelled', 'refunded'], true) => "Order {$order->code} is {$order->status}.",
                in_array($locked->status, ['expired', 'cancelled', 'failed'], true) => "Paid after the session was {$locked->status}.",
                default => null,
            };

            if ($problem) {
                $locked->update(['status' => 'review', 'provider_trx' => $result->trxId, 'note' => Str::limit("Money received but not credited — {$problem} Check with the provider.", 250, '')]);
                $this->audit($locked, 'gateway_payment.needs_review', ['reason' => $problem, 'trx' => $result->trxId]);

                return $locked;
            }

            $balance = $this->ledger->balanceMinor($order);
            $payment = Payment::create([
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'kind' => 'payment', 'method' => 'online',
                'reference' => Str::limit($locked->gateway_key.':'.$result->trxId, 100, ''), 'amount' => Money::toDecimal($expected), 'tip' => 0,
                'idempotency_key' => 'gw:'.$locked->id, 'created_by' => $actorId ?? $locked->created_by,
            ]);
            $this->ledger->refreshState($order);

            $note = $expected > $balance ? 'The order was paid another way meanwhile: the customer overpaid by '.number_format(($expected - $balance) / 100, 2).' — refund the difference.' : null;
            $locked->update(['status' => 'paid', 'provider_trx' => $result->trxId, 'payment_id' => $payment->id, 'settled_at' => now(), 'note' => $note]);
            $this->audit($locked, 'gateway_payment.settled', ['order' => $order->code, 'amount' => $expected / 100, 'trx' => $result->trxId, 'via' => $via ?? 'provider verification', 'overpaid' => $note !== null]);

            return $locked;
        });
    }

    private function audit(PaymentSession $session, string $action, array $changes): void
    {
        AuditLog::record(
            actorId: $session->created_by, actorName: 'Payment gateway ('.$session->gateway_key.')', action: $action, subjectType: PaymentSession::class,
            subjectId: $session->id, branchId: $session->branch_id, changes: $changes,
        );
    }
}
