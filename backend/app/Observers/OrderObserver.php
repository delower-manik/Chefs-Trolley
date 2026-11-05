<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Services\Notify\OrderNotifier;
use App\Services\Realtime\Realtime;
use App\Services\Webhooks\Webhooks;
use App\Support\Money;
use App\Support\OrderStage;

/**
 * One place that turns order changes into (a) a real-time push to the branch, (b) outgoing webhooks,
 * (c) customer notifications. Runs after the surrounding DB transaction commits, so nothing is sent
 * for work that later rolls back.
 */
class OrderObserver
{
    public bool $afterCommit = true;

    public function saved(Order $order): void
    {
        Realtime::publisher()->publish($order->branch_id, 'order.changed', [
            'id' => $order->id, 'code' => $order->code, 'status' => $order->status, 'kitchenState' => $order->kitchen_state,
            'paymentState' => $order->payment_state, 'diningTableId' => $order->dining_table_id, 'source' => $order->source,
        ]);

        if ($order->channel === 'web') {
            Realtime::publisher()->publishOrder($order->id, 'order.updated', ['stage' => OrderStage::of($order)]);
        }

        $created = $order->wasRecentlyCreated;
        $statusChanged = $order->wasChanged('status');

        if ($created && $order->status !== 'draft') {
            Webhooks::fire('order.created', $this->payload($order));
        }
        if ($statusChanged && $order->status === 'cancelled') {
            Webhooks::fire('order.cancelled', $this->payload($order));
        }
        if ($statusChanged && $order->status === 'delivered') {
            Webhooks::fire('order.delivered', $this->payload($order));
        }

        // Website orders earn loyalty points only once they are really finished (not on placement), so place-and-cancel can't farm points.
        if ($statusChanged && $order->channel === 'web' && $order->customer_id && in_array($order->status, ['completed', 'delivered'], true)) {
            $this->awardWebPoints($order);
        }

        if ($created || $statusChanged) {
            app(OrderNotifier::class)->orderChanged($order, $created);
        }
    }

    private function awardWebPoints(Order $order): void
    {
        $already = LoyaltyLedgerEntry::where('reference_type', Order::class)->where('reference_id', $order->id)->where('type', 'earned')->exists();
        $customer = Customer::find($order->customer_id);
        $points = intdiv(Money::toMinor($order->grand_total), 10000); // same rate as the till: 1 point per 100 BDT
        if ($already || ! $customer || $points < 1) {
            return;
        }
        LoyaltyLedgerEntry::record(
            customer: $customer, type: 'earned', pointsChange: $points, reason: "Earned from order {$order->code}",
            referenceType: Order::class, referenceId: $order->id,
        );
    }

    private function payload(Order $order): array
    {
        return [
            'id' => $order->id, 'code' => $order->code, 'status' => $order->status, 'source' => $order->source,
            'branchId' => $order->branch_id, 'subtotal' => (float) $order->subtotal, 'taxTotal' => (float) $order->tax_total,
            'discountTotal' => (float) $order->discount_total, 'deliveryFee' => (float) $order->delivery_fee,
            'grandTotal' => (float) $order->grand_total, 'paymentState' => $order->payment_state,
            'createdAt' => $order->created_at?->toIso8601String(),
        ];
    }
}
