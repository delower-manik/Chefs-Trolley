<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\PromotionExhaustedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddOrderItemsRequest;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\TransferTableRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\Ingredient;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Services\DiscountPolicy;
use App\Services\OrderLedger;
use App\Services\OrderPricer;
use App\Services\OrderStock;
use App\Support\Like;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    /**
     * GET /orders
     * Filters: status, type (source), branch_id, search. Paginated.
     * Branch scope is enforced by the EnsureBranchScope middleware, not by
     * trusting a client-supplied branch_id blindly.
     */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = Order::query()->with('items')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('source', $request->query('type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereRaw(Like::clause('code'), [Like::contains((string) $request->query('search'))]);
            })
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map($this->serialize(...)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Order $order)
    {
        Gate::authorize('view', $order);

        $order->load('items');

        return response()->json(['data' => $this->serialize($order)]);
    }

    /**
     * POST /orders
     * Core POS/dine-in/takeaway/delivery order creation. Prices are always
     * recomputed server-side from the current FoodItem/Modifier records --
     * a client can send food_item_id + quantity but never a trusted price
     * (Non-Negotiable Rule: never trust client-side authorization/values
     * for anything financial). Idempotency-Key (sent by the API client on
     * every write) prevents duplicate orders from a retried request.
     */
    public function store(CreateOrderRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json([
                'code' => 'branch_required',
                'message' => 'Select an active branch before creating an order.',
            ], 422);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey) {
            $existing = Order::where('idempotency_key', $idempotencyKey)->where('branch_id', $branchId)->first();
            if ($existing) {
                return response()->json(['data' => $this->serialize($existing->load('items'))], 200);
            }
        }

        // All prices come from the database via OrderPricer (client sends ids
        // and quantities only). Money below is integer paisa until it is
        // written to the DECIMAL columns. OrderRuleException => 422.
        $priced = app(OrderPricer::class)->price($branchId, $request->validated('items'));
        $lineItems = $priced->lines;
        $subtotal = $priced->subtotal;
        $taxTotal = $priced->tax;
        $taxInclusive = $priced->taxInclusive;
        $recipeConsumption = $priced->recipeConsumption;

        $discountTotal = Money::toMinor($request->validated('discount_total', 0));
        $deliveryFee = Money::toMinor($request->validated('delivery_fee', 0));
        $isHold = (bool) $request->validated('hold', false);
        $customerId = $request->validated('customer_id');
        $customer = $customerId ? Customer::find($customerId) : null;

        // A delivery zone's fee is authoritative over anything the client
        // sent in `delivery_fee` directly -- same "never trust a client-
        // supplied money value when a server-side source of truth exists"
        // principle as recipe pricing and promo discounts above.
        $deliveryZone = null;
        $deliveryZoneId = $request->validated('delivery_zone_id');
        if ($deliveryZoneId) {
            $deliveryZone = DeliveryZone::where('id', $deliveryZoneId)
                ->where('branch_id', $branchId)->where('is_active', true)->first();
            if (! $deliveryZone) {
                return response()->json(['code' => 'invalid_delivery_zone', 'message' => 'This delivery zone is not available.'], 422);
            }
            $deliveryFee = Money::toMinor($deliveryZone->delivery_fee);
        }

        // A table must belong to the order's branch, be in service, and not
        // already carry an open tab (add another round to that order instead).
        $table = null;
        if ($request->validated('dining_table_id')) {
            $table = DiningTable::find($request->validated('dining_table_id'));
            if (! $table || $table->branch_id !== $branchId) {
                return response()->json(['code' => 'invalid_table', 'message' => 'This table does not belong to the active branch.'], 422);
            }
            if ($table->status === 'out_of_service') {
                return response()->json(['code' => 'table_out_of_service', 'message' => "Table {$table->label} is out of service."], 422);
            }
            if (! $isHold && ($open = $table->openOrder())) {
                return response()->json([
                    'code' => 'table_has_open_order',
                    'message' => "Table {$table->label} already has an open order ({$open->code}). Add items to it instead.",
                    'errors' => ['orderId' => $open->id, 'orderCode' => $open->code],
                ], 422);
            }
        }

        // A promo code is validated up front (fast, deterministic checks);
        // the one thing that genuinely races under concurrency -- a global
        // usage_limit -- is enforced again inside the transaction via a
        // conditional atomic increment (see below), not trusted from this
        // pre-check alone.
        $promotion = null;
        $promoDiscount = 0;
        $promoCode = $request->validated('promo_code');
        if ($promoCode) {
            $promotion = Promotion::whereRaw('UPPER(code) = ?', [strtoupper(trim($promoCode))])->first();

            if (! $promotion || ! $promotion->is_active) {
                return response()->json(['code' => 'invalid_promo_code', 'message' => 'This promo code is not valid.'], 422);
            }
            if (! $promotion->isWithinValidityWindow()) {
                return response()->json(['code' => 'promo_code_expired', 'message' => 'This promo code is not active right now.'], 422);
            }
            if (! $promotion->hasRemainingUses()) {
                return response()->json(['code' => 'promo_code_exhausted', 'message' => 'This promo code has already been fully redeemed.'], 422);
            }
            if ($subtotal < Money::toMinor($promotion->min_order_amount)) {
                return response()->json([
                    'code' => 'promo_minimum_not_met',
                    'message' => 'This code needs a minimum order of '.number_format((float) $promotion->min_order_amount, 2).' to apply.',
                ], 422);
            }
            if ($promotion->usage_limit_per_customer !== null) {
                if (! $customer) {
                    return response()->json(['code' => 'promo_requires_customer', 'message' => 'This code is limited per customer -- select a customer first.'], 422);
                }
                $customerUses = PromotionRedemption::where('promotion_id', $promotion->id)->where('customer_id', $customer->id)->count();
                if ($customerUses >= $promotion->usage_limit_per_customer) {
                    return response()->json(['code' => 'promo_customer_limit_reached', 'message' => 'This customer has already used this code the maximum number of times.'], 422);
                }
            }

            $promoDiscount = $promotion->calculateDiscountMinor($subtotal);
        }

        // A hand-keyed discount is a permissioned, reasoned, capped action (Phase 13):
        // `discounts.apply` up to config('pos.max_discount_percent'), `discounts.override` unlimited.
        $manualDiscount = $discountTotal;
        $discountReason = null;
        if ($manualDiscount > 0) {
            $check = app(DiscountPolicy::class)->check(
                $request->user(), $manualDiscount, $subtotal + $priced->taxExclusive() - $promoDiscount
            );
            if ($check) {
                return $check;
            }
            $discountReason = trim((string) $request->input('discount_reason', ''));
            if ($discountReason === '') {
                return response()->json(['code' => 'discount_reason_required', 'message' => 'Give a reason for the discount.'], 422);
            }
        }

        // Never discount below zero: the combined discount is capped at what
        // the customer actually owes for goods (subtotal + added tax).
        $discountTotal = min($discountTotal + $promoDiscount, $subtotal + $priced->taxExclusive());
        $grandTotal = max(0, $subtotal + $priced->taxExclusive() - $discountTotal + $deliveryFee);

        // Recipe consumption draws from the branch's default warehouse --
        // POS checkout doesn't ask the cashier which storage location a
        // sale draws from (that would be over-engineering the checkout
        // flow); multi-location consumption routing can be revisited if a
        // real need for it shows up.
        $defaultWarehouse = $isHold ? null : Branch::findOrFail($branchId)->defaultWarehouse();

        // Retry once on a code collision (see Order::generateCode note on concurrency).
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $order = DB::transaction(function () use (
                    $request, $branchId, $lineItems, $subtotal, $taxTotal, $taxInclusive, $table,
                    $discountTotal, $deliveryFee, $grandTotal, $isHold, $idempotencyKey, $recipeConsumption,
                    $defaultWarehouse, $promotion, $promoDiscount, $customer, $deliveryZone, $manualDiscount, $discountReason
                ) {
                    $order = Order::create([
                        'code' => Order::generateCode(),
                        'source' => $request->validated('source'),
                        'branch_id' => $branchId,
                        'customer_id' => $request->validated('customer_id'),
                        'dining_table_id' => $table?->id,
                        'subtotal' => Money::toDecimal($subtotal),
                        'tax_total' => Money::toDecimal($taxTotal),
                        'tax_inclusive_total' => Money::toDecimal($taxInclusive),
                        'discount_total' => Money::toDecimal($discountTotal),
                        'manual_discount_total' => Money::toDecimal(min($manualDiscount, $discountTotal)),
                        'discount_reason' => $discountReason,
                        'discount_by' => $manualDiscount > 0 ? $request->user()->id : null,
                        'delivery_fee' => Money::toDecimal($deliveryFee),
                        'grand_total' => Money::toDecimal($grandTotal),
                        'status' => $isHold ? 'draft' : 'pending',
                        'kitchen_state' => 'new',
                        'idempotency_key' => $idempotencyKey,
                        'created_by' => $request->user()->id,
                    ]);

                    foreach ($lineItems as $item) {
                        $order->items()->create($item);
                    }

                    // A delivery order always gets a delivery_assignments
                    // row up front (status "pending", no rider yet) so the
                    // dispatch board can see it immediately, before anyone
                    // has assigned a rider -- see DeliveryController.
                    if ($request->validated('source') === 'delivery' && ! $isHold) {
                        DeliveryAssignment::create([
                            'order_id' => $order->id,
                            'branch_id' => $branchId,
                            'delivery_zone_id' => $deliveryZone?->id,
                            'delivery_address' => $request->validated('delivery_address') ?? $customer?->address,
                            'status' => 'pending',
                        ]);
                    }

                    if ($table && ! $isHold) {
                        $table->update(['status' => 'occupied']);
                    }

                    // Recipe consumption only fires once the order is actually
                    // sent to the kitchen -- a held/draft order hasn't
                    // committed the restaurant to making the dish yet, so it
                    // shouldn't debit stock. allowNegative: false makes this
                    // a hard stop: if fulfilling this order would take any
                    // ingredient below zero, StockLedgerEntry::record() throws
                    // InsufficientStockException *from inside its row lock*,
                    // which rolls back the whole order (nothing partially
                    // created) and is turned into a 422 below -- race-safe
                    // even if two checkouts for the last portion of an
                    // ingredient land at the same instant.
                    if (! $isHold) {
                        $this->consumeStock($order, $recipeConsumption, $defaultWarehouse, $request->user()->id);
                    }

                    // Redeem the promo code -- the conditional increment
                    // (WHERE used_count < usage_limit) is the actual
                    // race-safe enforcement of a global usage cap; the
                    // upfront check above is just a fast, friendly rejection
                    // for the overwhelmingly common non-race case.
                    if ($promotion) {
                        $updated = Promotion::where('id', $promotion->id)
                            ->when($promotion->usage_limit !== null, fn ($q) => $q->where('used_count', '<', $promotion->usage_limit))
                            ->increment('used_count');

                        if ($updated === 0 && $promotion->usage_limit !== null) {
                            throw new PromotionExhaustedException($promotion);
                        }

                        PromotionRedemption::create([
                            'promotion_id' => $promotion->id,
                            'order_id' => $order->id,
                            'customer_id' => $customer?->id,
                            'discount_amount' => Money::toDecimal($promoDiscount),
                            'created_at' => now(),
                        ]);
                    }

                    // Loyalty points are earned once the order is actually
                    // placed (not on a held draft), at a fixed rate for now
                    // -- see README for the per-branch-configurable rate
                    // this should become.
                    if (! $isHold && $customer) {
                        $pointsEarned = intdiv($grandTotal, 10000); // 1 point per 100 BDT
                        if ($pointsEarned > 0) {
                            LoyaltyLedgerEntry::record(
                                customer: $customer,
                                type: 'earned',
                                pointsChange: $pointsEarned,
                                reason: "Earned from order {$order->code}",
                                referenceType: Order::class,
                                referenceId: $order->id,
                                actorId: $request->user()->id,
                            );
                        }
                    }

                    return $order;
                });

                break;
            } catch (InsufficientStockException $e) {
                return response()->json([
                    'code' => 'insufficient_stock',
                    'message' => $e->getMessage(),
                    'errors' => ['ingredient' => $e->ingredient->name, 'available' => $e->available, 'requested' => $e->requested],
                ], 422);
            } catch (PromotionExhaustedException $e) {
                return response()->json(['code' => 'promo_code_exhausted', 'message' => $e->getMessage()], 422);
            } catch (QueryException $e) {
                // A replayed offline order racing its own first attempt (two tabs, a retry
                // after a timeout): the other request already created it, so return that one.
                if ($idempotencyKey && ($dupe = Order::where('idempotency_key', $idempotencyKey)->where('branch_id', $branchId)->first())) {
                    return response()->json(['data' => $this->serialize($dupe->load('items'))], 200);
                }
                if ($attempt === 2) {
                    throw $e;
                }

                // Unique constraint collision on `code` -- regenerate and retry.
                continue;
            }
        }

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $isHold ? 'order.held' : 'order.created',
            subjectType: Order::class,
            subjectId: $order->id,
            branchId: $order->branch_id,
            changes: ['after' => $order->only(['code', 'source', 'grand_total', 'status'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => [
            ...$this->serialize($order->load('items')),
            'appliedPromoCode' => $promotion?->code,
            'loyaltyPointsEarned' => ! $isHold && $customer ? intdiv($grandTotal, 10000) : 0,
        ]], 201);
    }

    /**
     * POST /orders/{order}/cancel
     * Only permitted from cancellable states (draft..preparing); enforces
     * both a permission check (route middleware) and a state-machine check.
     * Every cancellation is audited (Non-Negotiable Rule #10).
     */
    public function cancel(CancelOrderRequest $request, Order $order)
    {
        Gate::authorize('cancel', $order);

        // Lock the order so a payment landing at the same moment cannot slip in between the check and the cancel.
        $refused = DB::transaction(function () use ($request, &$order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->isCancellable()) {
                return response()->json([
                    'code' => 'invalid_order_state',
                    'message' => "Order {$order->code} can no longer be cancelled from status \"{$order->status}\".",
                ], 422);
            }
            if (app(OrderLedger::class)->netMinor($order) > 0) {
                return response()->json([
                    'code' => 'order_has_payments',
                    'message' => "Order {$order->code} has payments on it. Refund them first, then cancel.",
                ], 422);
            }

            $before = $order->only(['status', 'cancelled_at', 'cancellation_reason']);
            $order->update([
                'status' => 'cancelled',
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $request->validated('reason'),
            ]);
            $order->diningTable?->releaseIfIdle();
            AuditLog::record(
                actorId: $request->user()->id,
                actorName: $request->user()->name,
                action: 'order.cancelled',
                subjectType: Order::class,
                subjectId: $order->id,
                branchId: $order->branch_id,
                changes: ['before' => $before, 'after' => $order->only(['status', 'cancelled_at', 'cancellation_reason'])],
                ipAddress: $request->ip(),
            );

            return null;
        });
        if ($refused) {
            return $refused;
        }

        return response()->json(['data' => $this->serialize($order->fresh('items'))]);
    }

    /**
     * POST /orders/{order}/items
     * Waiter tabs: adds another round to an open order. Priced by the same
     * OrderPricer as a new order, debits stock for the new items, puts them in
     * the kitchen as "new", and grows the totals. A promo already applied is
     * not re-evaluated (its discount stays as it was); loyalty points are
     * earned on the increase.
     */
    public function addItems(AddOrderItemsRequest $request, Order $order)
    {
        Gate::authorize('update', $order);

        if (in_array($order->status, DiningTable::CLOSED_ORDER_STATUSES, true)) {
            return response()->json([
                'code' => 'order_closed',
                'message' => "Order {$order->code} is {$order->status} and can't take more items.",
            ], 422);
        }

        $priced = app(OrderPricer::class)->price($order->branch_id, $request->validated('items'));
        $warehouse = Branch::findOrFail($order->branch_id)->defaultWarehouse();

        try {
            DB::transaction(function () use ($request, $order, $priced, $warehouse) {
                $order = Order::lockForUpdate()->findOrFail($order->id);

                foreach ($priced->lines as $line) {
                    $order->items()->create($line);
                }

                $subtotal = Money::toMinor($order->subtotal) + $priced->subtotal;
                $tax = Money::toMinor($order->tax_total) + $priced->tax;
                $taxIncl = Money::toMinor($order->tax_inclusive_total) + $priced->taxInclusive;
                $discount = Money::toMinor($order->discount_total);
                $oldGrand = Money::toMinor($order->grand_total);
                $grand = max(0, $subtotal + ($tax - $taxIncl) - $discount + Money::toMinor($order->delivery_fee));

                $order->update([
                    'subtotal' => Money::toDecimal($subtotal),
                    'tax_total' => Money::toDecimal($tax),
                    'tax_inclusive_total' => Money::toDecimal($taxIncl),
                    'grand_total' => Money::toDecimal($grand),
                    // The kitchen has new work again.
                    'kitchen_state' => 'new',
                    'status' => 'pending',
                ]);

                $this->consumeStock($order, $priced->recipeConsumption, $warehouse, $request->user()->id);
                // A tab that was already paid has a balance again once a new round lands.
                app(OrderLedger::class)->refreshState($order);

                $extraPoints = intdiv($grand, 10000) - intdiv($oldGrand, 10000);
                if ($order->customer_id && $extraPoints > 0) {
                    LoyaltyLedgerEntry::record(
                        customer: $order->customer,
                        type: 'earned',
                        pointsChange: $extraPoints,
                        reason: "Earned from additional items on order {$order->code}",
                        referenceType: Order::class,
                        referenceId: $order->id,
                        actorId: $request->user()->id,
                    );
                }
            });
        } catch (InsufficientStockException $e) {
            return response()->json([
                'code' => 'insufficient_stock',
                'message' => $e->getMessage(),
                'errors' => ['ingredient' => $e->ingredient->name, 'available' => $e->available, 'requested' => $e->requested],
            ], 422);
        }

        $order->refresh();
        $this->recordAudit($request, 'order.items_added', $order, ['added' => count($priced->lines), 'grand_total' => $order->grand_total]);

        return response()->json(['data' => $this->serialize($order->load('items'))]);
    }

    /**
     * POST /orders/{order}/transfer-table
     * Moves an open tab to another free table in the same branch.
     */
    public function transferTable(TransferTableRequest $request, Order $order)
    {
        Gate::authorize('update', $order);

        if (in_array($order->status, DiningTable::CLOSED_ORDER_STATUSES, true)) {
            return response()->json(['code' => 'order_closed', 'message' => "Order {$order->code} is {$order->status}."], 422);
        }

        $target = DiningTable::find($request->validated('dining_table_id'));
        if (! $target || $target->branch_id !== $order->branch_id) {
            return response()->json(['code' => 'invalid_table', 'message' => 'This table does not belong to the order\'s branch.'], 422);
        }
        if ($target->id === $order->dining_table_id) {
            return response()->json(['code' => 'same_table', 'message' => 'The order is already on this table.'], 422);
        }
        if ($target->status === 'out_of_service') {
            return response()->json(['code' => 'table_out_of_service', 'message' => "Table {$target->label} is out of service."], 422);
        }
        if ($open = $target->openOrder()) {
            return response()->json([
                'code' => 'table_has_open_order',
                'message' => "Table {$target->label} already has an open order ({$open->code}).",
            ], 422);
        }

        $from = $order->diningTable;
        DB::transaction(function () use ($order, $target, $from) {
            $order->update(['dining_table_id' => $target->id]);
            $target->update(['status' => 'occupied']);
            $from?->releaseIfIdle();
        });

        $this->recordAudit($request, 'order.table_transferred', $order, ['from' => $from?->label, 'to' => $target->label]);

        return response()->json(['data' => $this->serialize($order->fresh('items'))]);
    }

    /** Debits ingredient stock for an order's recipes, all-or-nothing (rolls back the caller's transaction on shortage). */
    private function consumeStock(Order $order, array $recipeConsumption, Warehouse $warehouse, string $actorId): void
    {
        app(OrderStock::class)->consume($order, $recipeConsumption, $warehouse, $actorId);
    }

    private function serialize(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'source' => $order->source,
            'branchId' => $order->branch_id,
            'branchName' => $order->branch?->name,
            'customerId' => $order->customer_id,
            'diningTableId' => $order->dining_table_id,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'foodItemId' => $item->food_item_id,
                'comboId' => $item->combo_id,
                'comboName' => $item->combo_name,
                'kitchenStationId' => $item->kitchen_station_id,
                'kitchenState' => $item->kitchen_state,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unitPrice' => (float) $item->unit_price,
                'total' => (float) $item->total,
                'modifiers' => $item->modifiers ?? [],
                'notes' => $item->notes,
            ]),
            'subtotal' => (float) $order->subtotal,
            'taxTotal' => (float) $order->tax_total,
            'taxInclusiveTotal' => (float) $order->tax_inclusive_total,
            'discountTotal' => (float) $order->discount_total,
            'manualDiscountTotal' => (float) $order->manual_discount_total,
            'discountReason' => $order->discount_reason,
            'amountPaid' => Money::toFloat($ledger = app(OrderLedger::class)->netMinor($order)),
            'balanceDue' => Money::toFloat(max(0, Money::toMinor($order->grand_total) - $ledger)),
            'deliveryFee' => (float) $order->delivery_fee,
            'grandTotal' => (float) $order->grand_total,
            'paymentState' => $order->payment_state,
            'kitchenState' => $order->kitchen_state,
            'status' => $order->status,
            'createdAt' => $order->created_at,
            'updatedAt' => $order->updated_at,
        ];
    }
}
