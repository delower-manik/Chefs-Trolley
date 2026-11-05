<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\DiscountPolicy;
use App\Services\OrderLedger;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Manual discounts on an existing, still-unpaid order. */
class DiscountController extends Controller
{
    /** PUT /orders/{order}/discount  {type: percent|fixed, value, reason} */
    public function apply(Request $request, Order $order, OrderLedger $ledger, DiscountPolicy $policy)
    {
        Gate::authorize('update', $order);
        $data = $request->validate([
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        return DB::transaction(function () use ($request, $order, $ledger, $policy, $data) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($refused = $this->guard($order, $ledger)) {
                return $refused;
            }

            $promo = Money::toMinor($order->discount_total) - Money::toMinor($order->manual_discount_total);
            $base = Money::toMinor($order->subtotal) + Money::toMinor($order->tax_total) - Money::toMinor($order->tax_inclusive_total);
            $available = max(0, $base - $promo);
            $amount = $data['type'] === 'percent' ? Money::percent($available, $data['value']) : Money::toMinor($data['value']);

            if ($check = $policy->check($request->user(), $amount, $available)) {
                return $check;
            }

            $before = (float) $order->manual_discount_total;
            $order->update([
                'manual_discount_total' => Money::toDecimal($amount),
                'discount_total' => Money::toDecimal($promo + $amount),
                'discount_reason' => $data['reason'],
                'discount_by' => $request->user()->id,
            ]);
            $ledger->recomputeGrand($order);
            $this->recordAudit($request, 'order.discount_applied', $order, [
                'before' => $before, 'after' => Money::toFloat($amount), 'type' => $data['type'], 'reason' => $data['reason'],
            ]);

            return response()->json(['data' => $this->totals($order->fresh())]);
        });
    }

    /** DELETE /orders/{order}/discount */
    public function remove(Request $request, Order $order, OrderLedger $ledger)
    {
        Gate::authorize('update', $order);

        return DB::transaction(function () use ($request, $order, $ledger) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($refused = $this->guard($order, $ledger)) {
                return $refused;
            }
            if (! $request->user()->hasPermissionKey('discounts.apply') && ! $request->user()->hasPermissionKey('discounts.override')) {
                return response()->json(['code' => 'discount_not_permitted', 'message' => 'You are not allowed to change discounts.'], 403);
            }

            $before = (float) $order->manual_discount_total;
            $promo = Money::toMinor($order->discount_total) - Money::toMinor($order->manual_discount_total);
            $order->update([
                'manual_discount_total' => 0, 'discount_total' => Money::toDecimal($promo),
                'discount_reason' => null, 'discount_by' => null,
            ]);
            $ledger->recomputeGrand($order);
            $this->recordAudit($request, 'order.discount_removed', $order, ['before' => $before]);

            return response()->json(['data' => $this->totals($order->fresh())]);
        });
    }

    private function guard(Order $order, OrderLedger $ledger)
    {
        if (in_array($order->status, ['draft', 'cancelled', 'refunded'], true)) {
            return response()->json(['code' => 'order_closed', 'message' => "Order {$order->code} can't be discounted while it is {$order->status}."], 422);
        }
        if ($order->payments()->whereNull('voided_at')->exists()) {
            return response()->json(['code' => 'order_has_payments', 'message' => 'Void or refund the payments before changing the discount.'], 422);
        }

        return null;
    }

    private function totals(Order $order): array
    {
        return [
            'discountTotal' => (float) $order->discount_total,
            'manualDiscountTotal' => (float) $order->manual_discount_total,
            'discountReason' => $order->discount_reason,
            'grandTotal' => (float) $order->grand_total,
        ];
    }
}
