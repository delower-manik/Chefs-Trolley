<?php

namespace App\Services\Web;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderRuleException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Services\AppSettings;
use App\Services\OrderPricer;
use App\Services\OrderStock;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Places an order for a signed-in website customer. Same rules as the till, minus everything a customer must never control:
 * no manual discount, no tables, no custom delivery fee, no price of any kind from the client. Money is integer paisa until written.
 *
 * @throws OrderRuleException with a customer-safe message and a stable code
 */
class WebOrderPlacer
{
    public const MAX_OPEN_ORDERS = 5;

    public function __construct(private OrderPricer $pricer, private OrderStock $stock, private AppSettings $settings) {}

    /**
     * @param  array{branch_id:string, fulfilment:string, items:array, promo_code?:?string, delivery_zone_id?:?string, delivery_address?:?string, phone?:?string, notes?:?string}  $in
     */
    public function place(Customer $customer, array $in, ?string $idempotencyKey): Order
    {
        if (! $this->settings->get('web.ordering')) {
            throw new OrderRuleException('ordering_closed', 'Online ordering is not open right now.');
        }
        $branch = Branch::where('id', $in['branch_id'])->where('is_active', true)->first();
        if (! $branch) {
            throw new OrderRuleException('invalid_branch', 'That restaurant branch is not available.');
        }

        if ($idempotencyKey) {
            $existing = Order::where('idempotency_key', 'web:'.$customer->id.':'.$idempotencyKey)->first();
            if ($existing) {
                return $existing; // a retried tap, not a second order
            }
        }

        $open = Order::where('customer_id', $customer->id)->where('channel', 'web')->whereNotIn('status', DiningTable::CLOSED_ORDER_STATUSES)->count();
        if ($open >= self::MAX_OPEN_ORDERS) {
            throw new OrderRuleException('too_many_open_orders', 'You already have several orders in progress. Please wait for them to finish.');
        }

        $delivery = $in['fulfilment'] === 'delivery';
        if (! $delivery && ! $this->settings->get('web.pickup')) {
            throw new OrderRuleException('pickup_unavailable', 'Pickup is not available right now.');
        }

        $zone = null;
        $fee = 0;
        if ($delivery) {
            $zone = DeliveryZone::where('id', $in['delivery_zone_id'] ?? null)->where('branch_id', $branch->id)->where('is_active', true)->first();
            if (! $zone) {
                throw new OrderRuleException('invalid_delivery_zone', 'Choose a delivery area we serve.');
            }
            if (trim((string) ($in['delivery_address'] ?? '')) === '') {
                throw new OrderRuleException('address_required', 'Enter your delivery address.');
            }
            $fee = Money::toMinor($zone->delivery_fee);
        }

        $items = collect($in['items'])->map(fn ($l) => [
            'food_item_id' => $l['food_item_id'] ?? null, 'combo_id' => $l['combo_id'] ?? null, 'quantity' => (int) $l['quantity'],
            'notes' => isset($l['notes']) ? mb_substr((string) $l['notes'], 0, 255) : null, 'modifier_ids' => $l['modifier_ids'] ?? [],
        ])->all();
        $priced = $this->pricer->price($branch->id, $items);

        $goods = $priced->subtotal + $priced->taxExclusive();
        $min = Money::toMinor((string) $this->settings->get('web.min_order'));
        if ($min > 0 && $goods < $min) {
            throw new OrderRuleException('below_minimum', 'The minimum order is '.number_format($min / 100).'.');
        }

        $promotion = null;
        $promoDiscount = 0;
        if (! empty($in['promo_code'])) {
            $promotion = Promotion::whereRaw('UPPER(code) = ?', [strtoupper(trim($in['promo_code']))])->first();
            if (! $promotion || ! $promotion->is_active) {
                throw new OrderRuleException('invalid_promo_code', 'This promo code is not valid.');
            }
            if (! $promotion->isWithinValidityWindow()) {
                throw new OrderRuleException('promo_code_expired', 'This promo code is not active right now.');
            }
            if (! $promotion->hasRemainingUses()) {
                throw new OrderRuleException('promo_code_exhausted', 'This promo code has already been fully redeemed.');
            }
            if ($priced->subtotal < Money::toMinor($promotion->min_order_amount)) {
                throw new OrderRuleException('promo_minimum_not_met', 'This code needs a minimum order of '.number_format((float) $promotion->min_order_amount).' to apply.');
            }
            if ($promotion->usage_limit_per_customer !== null
                && PromotionRedemption::where('promotion_id', $promotion->id)->where('customer_id', $customer->id)->count() >= $promotion->usage_limit_per_customer) {
                throw new OrderRuleException('promo_customer_limit_reached', 'You have already used this code the maximum number of times.');
            }
            $promoDiscount = $promotion->calculateDiscountMinor($priced->subtotal);
        }

        $discount = min($promoDiscount, $goods);
        $grand = max(0, $goods - $discount + $fee);
        $warehouse = $branch->defaultWarehouse();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($customer, $in, $branch, $priced, $zone, $fee, $discount, $grand, $promotion, $promoDiscount, $warehouse, $idempotencyKey, $delivery) {
                    $order = Order::create([
                        'code' => Order::generateCode(), 'source' => $delivery ? 'delivery' : 'takeaway', 'channel' => 'web', 'branch_id' => $branch->id,
                        'customer_id' => $customer->id, 'subtotal' => Money::toDecimal($priced->subtotal), 'tax_total' => Money::toDecimal($priced->tax),
                        'tax_inclusive_total' => Money::toDecimal($priced->taxInclusive), 'discount_total' => Money::toDecimal($discount),
                        'manual_discount_total' => Money::toDecimal(0), 'delivery_fee' => Money::toDecimal($fee), 'grand_total' => Money::toDecimal($grand),
                        'status' => 'pending', 'kitchen_state' => 'new', 'delivery_phone' => $in['phone'] ?? $customer->phone,
                        'idempotency_key' => $idempotencyKey ? 'web:'.$customer->id.':'.$idempotencyKey : null, 'created_by' => null,
                    ]);
                    foreach ($priced->lines as $line) {
                        $order->items()->create($line);
                    }
                    if ($delivery) {
                        DeliveryAssignment::create([
                            'order_id' => $order->id, 'branch_id' => $branch->id, 'delivery_zone_id' => $zone->id,
                            'delivery_address' => mb_substr(trim((string) $in['delivery_address']), 0, 255), 'status' => 'pending',
                        ]);
                    }
                    $this->stock->consume($order, $priced->recipeConsumption, $warehouse, null);
                    if ($promotion) {
                        $updated = Promotion::where('id', $promotion->id)
                            ->when($promotion->usage_limit !== null, fn ($q) => $q->where('used_count', '<', $promotion->usage_limit))->increment('used_count');
                        if ($updated === 0 && $promotion->usage_limit !== null) {
                            throw new OrderRuleException('promo_code_exhausted', 'This promo code has already been fully redeemed.');
                        }
                        PromotionRedemption::create([
                            'promotion_id' => $promotion->id, 'order_id' => $order->id, 'customer_id' => $customer->id,
                            'discount_amount' => Money::toDecimal($promoDiscount), 'created_at' => now(),
                        ]);
                    }

                    return $order;
                });
            } catch (InsufficientStockException $e) {
                throw new OrderRuleException('insufficient_stock', 'Sorry, some items just ran out: '.$e->ingredient->name.'. Please adjust your order.');
            } catch (QueryException $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }
        throw new OrderRuleException('order_failed', 'Could not place the order. Please try again.');
    }
}
