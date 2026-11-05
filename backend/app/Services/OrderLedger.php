<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Support\Money;

/** Money-in/money-out bookkeeping for an order, all in integer paisa. */
class OrderLedger
{
    public function paidMinor(Order $order): int
    {
        return $this->sum($order, 'payment');
    }

    public function refundedMinor(Order $order): int
    {
        return $this->sum($order, 'refund');
    }

    public function netMinor(Order $order): int
    {
        return $this->paidMinor($order) - $this->refundedMinor($order);
    }

    public function balanceMinor(Order $order): int
    {
        return max(0, Money::toMinor($order->grand_total) - $this->netMinor($order));
    }

    /** payment_state follows the ledger: unpaid / paid / partially_refunded / refunded. */
    public function refreshState(Order $order): void
    {
        $net = $this->netMinor($order);
        $refunded = $this->refundedMinor($order);
        $grand = Money::toMinor($order->grand_total);

        $state = match (true) {
            $refunded > 0 && $net <= 0 => 'refunded',
            $refunded > 0 => 'partially_refunded',
            $net >= $grand && $grand > 0 => 'paid',
            default => 'unpaid',
        };
        // A zero-total order (fully discounted) counts as paid once any payment row exists.
        if ($grand === 0 && $refunded === 0 && $order->payments()->where('kind', 'payment')->whereNull('voided_at')->exists()) {
            $state = 'paid';
        }

        if ($order->payment_state !== $state) {
            $order->update(['payment_state' => $state]);
        }
    }

    /** Recomputes grand_total after a discount change. */
    public function recomputeGrand(Order $order): void
    {
        $grand = max(0, Money::toMinor($order->subtotal)
            + Money::toMinor($order->tax_total) - Money::toMinor($order->tax_inclusive_total)
            - Money::toMinor($order->discount_total)
            + Money::toMinor($order->delivery_fee));
        $order->update(['grand_total' => Money::toDecimal($grand)]);
    }

    /**
     * Puts back the ingredient stock this order consumed. Idempotent: an order is restocked once.
     * Returns the number of ledger lines restocked.
     */
    public function restock(Order $order, ?string $actorId, string $why = 'refund of order'): int
    {
        if ($order->stock_restocked_at) {
            return 0;
        }

        $rows = StockLedgerEntry::where('reference_type', Order::class)
            ->where('reference_id', $order->id)->where('type', 'sale_consumption')->get()
            ->groupBy(fn ($e) => $e->ingredient_id.'|'.$e->warehouse_id);

        $count = 0;
        foreach ($rows as $entries) {
            $first = $entries->first();
            $ingredient = Ingredient::find($first->ingredient_id);
            $warehouse = Warehouse::find($first->warehouse_id);
            $qty = -1 * (float) $entries->sum('quantity_change'); // consumption is negative
            if (! $ingredient || ! $warehouse || $qty <= 0) {
                continue;
            }
            StockLedgerEntry::record(
                ingredient: $ingredient, warehouse: $warehouse, type: 'correction', quantityChange: $qty,
                reason: "Restocked: {$why} {$order->code}", referenceType: Order::class,
                referenceId: $order->id, actorId: $actorId,
            );
            $count++;
        }

        $order->update(['stock_restocked_at' => now()]);

        return $count;
    }

    private function sum(Order $order, string $kind): int
    {
        return Payment::where('order_id', $order->id)->where('kind', $kind)->whereNull('voided_at')
            ->get(['amount'])->sum(fn ($p) => Money::toMinor($p->amount));
    }
}
