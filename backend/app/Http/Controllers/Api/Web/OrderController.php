<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Combo;
use App\Models\DeliveryAssignment;
use App\Models\FoodItem;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Services\AppSettings;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayPayments;
use App\Services\OrderLedger;
use App\Services\Web\WebOrderPlacer;
use App\Support\BdPhone;
use App\Support\Money;
use App\Support\OrderStage;
use Illuminate\Http\Request;

/** A signed-in customer's own orders. Every lookup is scoped to the customer: someone else's order id is simply a 404. */
class OrderController extends Controller
{
    public function __construct(private OrderLedger $ledger, private AppSettings $settings) {}

    /** POST /web/orders — place an order. Payment is chosen here: cod, or online with a gateway (then call /pay). */
    public function store(Request $request, WebOrderPlacer $placer, GatewayManager $gateways, GatewayPayments $payments)
    {
        $customer = $request->attributes->get('customer');
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'fulfilment' => ['required', 'in:delivery,pickup'],
            'payment' => ['required', 'in:cod,online'],
            'gateway' => ['nullable', 'required_if:payment,online', 'string', 'max:40'],
            'delivery_zone_id' => ['nullable', 'uuid'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.food_item_id' => ['nullable', 'required_without:items.*.combo_id', 'uuid'],
            'items.*.combo_id' => ['nullable', 'required_without:items.*.food_item_id', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifier_ids' => ['nullable', 'array', 'max:20'],
            'items.*.modifier_ids.*' => ['uuid'],
        ]);

        if (isset($data['phone'])) {
            $data['phone'] = BdPhone::normalize($data['phone']);
            if (! $data['phone']) {
                return response()->json(['code' => 'invalid_phone', 'message' => 'Enter a valid contact number.'], 422);
            }
        }
        if ($data['payment'] === 'cod' && ! $this->settings->get('web.cod')) {
            return response()->json(['code' => 'cod_unavailable', 'message' => 'Cash on delivery is not available. Please pay online.'], 422);
        }
        if ($data['payment'] === 'online') {
            $gateways->ensureCatalog();
            $class = GatewayManager::DRIVERS[$data['gateway']] ?? null;
            if (! $class || $class::manual() || $gateways->usableConfig($data['gateway']) === null) {
                return response()->json(['code' => 'gateway_unavailable', 'message' => 'That payment method is not available.'], 422);
            }
        }

        $order = $placer->place($customer, $data, $request->header('Idempotency-Key'));

        $payment = null;
        if ($data['payment'] === 'online' && $this->ledger->balanceMinor($order) > 0) {
            $session = $payments->start($order, $data['gateway'], null, null, rtrim((string) config('app.url'), '/').'/api/v1');
            // The order exists either way; if the provider is down the customer can retry from the order page.
            $payment = is_string($session) ? ['error' => $session] : $this->session($session);
        }

        return response()->json(['data' => [...$this->serialize($order->load('items')), 'payment' => $payment]], 201);
    }

    public function index(Request $request)
    {
        $customer = $request->attributes->get('customer');
        $orders = Order::where('customer_id', $customer->id)->where('channel', 'web')->with('items')->latest()->limit(30)->get();

        return response()->json(['data' => $orders->map(fn ($o) => $this->serialize($o))]);
    }

    public function show(Request $request, string $order)
    {
        return response()->json(['data' => $this->serialize($this->own($request, $order)->load('items'))]);
    }

    /** POST /web/orders/{order}/pay {gateway} — (re)start an online payment for an unpaid order. */
    public function pay(Request $request, string $order, GatewayManager $gateways, GatewayPayments $payments)
    {
        $o = $this->own($request, $order);
        $data = $request->validate(['gateway' => ['required', 'string', 'max:40']]);
        $gateways->ensureCatalog();
        $class = GatewayManager::DRIVERS[$data['gateway']] ?? null;
        if (! $class || $class::manual() || $gateways->usableConfig($data['gateway']) === null) {
            return response()->json(['code' => 'gateway_unavailable', 'message' => 'That payment method is not available.'], 422);
        }
        $session = $payments->start($o, $data['gateway'], null, null, url('/api/v1'));
        if (is_string($session)) {
            return response()->json(['code' => 'gateway_unavailable', 'message' => $session], 422);
        }

        return response()->json(['data' => $this->session($session)], 201);
    }

    /** POST /web/orders/{order}/cancel — only while the kitchen has not started and nothing has been paid. */
    public function cancel(Request $request, string $order)
    {
        $o = $this->own($request, $order);
        if ($o->status !== 'pending' || $o->kitchen_state !== 'new' || $this->ledger->netMinor($o) > 0) {
            return response()->json(['code' => 'cannot_cancel', 'message' => 'This order can no longer be cancelled online. Please call the restaurant.'], 422);
        }
        $o->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Cancelled by customer (website)']);
        $this->ledger->restock($o, null, 'cancellation of order');
        AuditLog::record(actorId: null, actorName: 'customer:'.$request->attributes->get('customer')->phone, action: 'order.cancelled', subjectType: Order::class,
            subjectId: $o->id, branchId: $o->branch_id, changes: ['after' => ['status' => 'cancelled', 'by' => 'customer']], ipAddress: $request->ip());

        return response()->json(['data' => $this->serialize($o->fresh('items'))]);
    }

    /**
     * GET /web/orders/{order}/reorder — what the customer's cart would be, built from TODAY's menu. Display only:
     * the real order is priced again by the server when placed. Anything unavailable, moved or changed is listed in
     * `skipped` rather than silently altered.
     */
    public function reorder(Request $request, string $order)
    {
        $o = $this->own($request, $order)->load('items');
        if (! Branch::where('id', $o->branch_id)->where('is_active', true)->exists()) {
            return response()->json(['code' => 'branch_unavailable', 'message' => 'This branch is not taking orders.'], 422);
        }
        $lines = [];
        $skipped = [];

        foreach ($o->items->whereNull('combo_id') as $row) {
            $item = FoodItem::with('modifierGroups.modifiers')->find($row->food_item_id);
            $live = $item && $item->is_available && ($item->branch_id === null || $item->branch_id === $o->branch_id);
            $wanted = collect($row->modifiers ?? [])->pluck('id')->filter()->values();
            $mods = $live ? $item->modifierGroups->flatMap->modifiers->where('is_active', true)->whereIn('id', $wanted)->values() : collect();
            if (! $live || $mods->count() !== $wanted->count()) {
                $skipped[] = $row->name;

                continue;
            }
            $lines[] = [
                'itemId' => $item->id, 'name' => $item->name, 'quantity' => (int) $row->quantity, 'notes' => $row->notes,
                'modifierIds' => $mods->pluck('id')->all(), 'modifierNames' => $mods->pluck('name')->all(),
                'unitPrice' => (float) $item->base_price + (float) $mods->sum('price'),
            ];
        }

        foreach ($o->items->whereNotNull('combo_id')->groupBy('combo_id') as $comboId => $rows) {
            $combo = Combo::with('items')->find($comboId);
            $first = $rows->first();
            $unit = $combo?->items->firstWhere('food_item_id', $first->food_item_id)?->quantity ?: 1;
            if (! $combo || $combo->branch_id !== $o->branch_id || ! $combo->isOrderableNow()) {
                $skipped[] = $first->combo_name ?: $first->name;

                continue;
            }
            $lines[] = ['comboId' => $combo->id, 'name' => $combo->name, 'quantity' => max(1, intdiv((int) $first->quantity, max(1, (int) $unit))), 'notes' => null, 'modifierIds' => [], 'modifierNames' => [], 'unitPrice' => (float) $combo->price];
        }

        return response()->json(['data' => ['branchId' => $o->branch_id, 'lines' => $lines, 'skipped' => $skipped]]);
    }

    private function own(Request $request, string $id): Order
    {
        return Order::where('id', $id)->where('customer_id', $request->attributes->get('customer')->id)->where('channel', 'web')->firstOrFail();
    }

    private function session(PaymentSession $s): array
    {
        return ['id' => $s->id, 'gateway' => $s->gateway_key, 'status' => $s->status, 'redirectUrl' => $s->redirect_url, 'amount' => (float) $s->amount];
    }

    private function serialize(Order $o): array
    {
        $net = $this->ledger->netMinor($o);
        $assignment = $o->source === 'delivery' ? DeliveryAssignment::with('rider')->where('order_id', $o->id)->first() : null;
        $stage = OrderStage::of($o, $assignment);

        return [
            'id' => $o->id, 'code' => $o->code, 'status' => $o->status, 'stage' => $stage, 'fulfilment' => $o->source === 'delivery' ? 'delivery' : 'pickup',
            'branchId' => $o->branch_id, 'createdAt' => $o->created_at,
            'items' => $o->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'total' => (float) $i->total, 'modifiers' => $i->modifiers ?? [], 'notes' => $i->notes]),
            'subtotal' => (float) $o->subtotal, 'tax' => (float) $o->tax_total - (float) $o->tax_inclusive_total, 'discount' => (float) $o->discount_total,
            'deliveryFee' => (float) $o->delivery_fee, 'total' => (float) $o->grand_total, 'paid' => Money::toFloat($net),
            'balanceDue' => Money::toFloat(max(0, Money::toMinor($o->grand_total) - $net)), 'paymentState' => $o->payment_state,
            'delivery' => $assignment ? ['address' => $assignment->delivery_address, 'status' => $assignment->status, 'riderName' => $assignment->rider?->name] : null,
        ];
    }
}
