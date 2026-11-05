<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\RiderCashCollection;
use App\Services\DeliveryFlow;
use App\Services\OrderLedger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The rider's own app. Everything here is scoped to the rider linked to the signed-in user: another rider's delivery is
 * a 404, and the rider can move only THEIR deliveries forward (pickup -> deliver / fail). Assigning, reassigning and
 * cancelling stay with the dispatcher (delivery.manage). Money is never taken from the client: the cash due is
 * computed from the order ledger and the rider only confirms the figure they saw.
 */
class AppController extends Controller
{
    public function __construct(private DeliveryFlow $flow, private OrderLedger $ledger) {}

    /** GET /rider/me */
    public function me(Request $request)
    {
        return response()->json(['data' => $this->profile($this->rider($request))]);
    }

    /** PUT /rider/status {status: available|offline} */
    public function status(Request $request)
    {
        $rider = $this->rider($request);
        $data = $request->validate(['status' => ['required', 'in:available,offline']]);
        if ($this->open($rider)->exists()) {
            return $this->error('has_open_deliveries', 'Finish or report your current deliveries before changing your status.');
        }
        $rider->update(['status' => $data['status']] + ($data['status'] === 'offline' ? $this->clearLocation() : []));

        return response()->json(['data' => $this->profile($rider->fresh())]);
    }

    /** GET /rider/deliveries?scope=active|history */
    public function deliveries(Request $request)
    {
        $rider = $this->rider($request);
        $history = $request->query('scope') === 'history';
        $q = DeliveryAssignment::with(['order.items', 'order.customer', 'zone'])->where('rider_id', $rider->id);

        if ($history) {
            $rows = $q->whereIn('status', ['delivered', 'failed'])->latest('updated_at')->limit(50)->get();
        } else {
            $rows = $q->whereIn('status', ['assigned', 'picked_up'])->whereHas('order', fn ($o) => $o->where('status', '!=', 'cancelled'))->orderBy('assigned_at')->get();
        }

        return response()->json(['data' => $rows->map(fn ($a) => $this->row($a, ! $history))]);
    }

    /** GET /rider/deliveries/{id} */
    public function show(Request $request, string $id)
    {
        $a = $this->own($request, $id);

        return response()->json(['data' => $this->row($a, $a->isOpen())]);
    }

    /** POST /rider/deliveries/{id}/pickup */
    public function pickup(Request $request, string $id)
    {
        $rider = $this->rider($request);
        $a = $this->own($request, $id);
        $order = $a->order;
        if ($order->status === 'cancelled') {
            return $this->error('order_cancelled', 'This order was cancelled. Do not collect it; call the restaurant.');
        }
        // Re-check under a row lock: a concurrent deliver/fail must not be overwritten.
        $bad = DB::transaction(function () use ($a, $order) {
            $a->refresh();
            $a = DeliveryAssignment::lockForUpdate()->findOrFail($a->id);
            if (! $a->canTransitionTo('picked_up')) {
                return true;
            }
            $this->flow->pickedUp($a, $order);

            return false;
        });
        if ($bad) {
            return $this->error('invalid_delivery_state', 'This delivery is not waiting to be picked up.');
        }
        $this->audit($request, $rider, 'delivery.picked_up', $a);

        return response()->json(['data' => $this->row($a->fresh(['order.items', 'order.customer', 'zone']), true)]);
    }

    /** POST /rider/deliveries/{id}/deliver {cash_collected?, recipient_name?, proof_note?} */
    public function deliver(Request $request, string $id)
    {
        $rider = $this->rider($request);
        $a = $this->own($request, $id);
        $data = $request->validate([
            'cash_collected' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'recipient_name' => ['nullable', 'string', 'max:150'],
            'proof_note' => ['nullable', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($request, $rider, $a, $data) {
            $order = Order::lockForUpdate()->findOrFail($a->order_id);
            $a = DeliveryAssignment::lockForUpdate()->findOrFail($a->id);
            if ($order->status === 'cancelled') {
                return $this->error('order_cancelled', 'This order was cancelled. Do not hand it over; call the restaurant.');
            }
            if (! $a->canTransitionTo('delivered')) {
                return $this->error('invalid_delivery_state', 'Pick this order up before marking it delivered.');
            }

            // What is owed is the SERVER's figure. The rider confirms it; a stale or wrong number is refused, not recorded.
            $due = $this->ledger->balanceMinor($order);
            $said = Money::toMinor($data['cash_collected'] ?? 0);
            if ($due > 0 && $said !== $due) {
                return response()->json(['code' => 'cash_mismatch', 'message' => 'The amount to collect is '.number_format(Money::toFloat($due), 2).'. Collect exactly that and enter it.', 'errors' => ['expected' => Money::toFloat($due)]], 422);
            }
            if ($due === 0 && $said > 0) {
                return $this->error('nothing_to_collect', 'This order is already paid. Do not collect cash.');
            }

            $this->flow->delivered($a, $order, $data['recipient_name'] ?? null, $data['proof_note'] ?? null);

            if ($due > 0) {
                // Cash in the rider's hands, not yet in a drawer: no shift until a manager settles it (then it joins that shift's expected cash).
                $payment = Payment::create([
                    'order_id' => $order->id, 'branch_id' => $order->branch_id, 'shift_id' => null, 'kind' => 'payment', 'method' => 'cash',
                    'reference' => 'rider:'.$rider->id, 'amount' => Money::toDecimal($due), 'tip' => 0, 'created_by' => $request->user()->id,
                ]);
                RiderCashCollection::create([
                    'branch_id' => $order->branch_id, 'rider_id' => $rider->id, 'order_id' => $order->id, 'delivery_assignment_id' => $a->id,
                    'payment_id' => $payment->id, 'amount' => Money::toDecimal($due), 'collected_at' => now(),
                ]);
                $this->ledger->refreshState($order);
            }
            $this->audit($request, $rider, 'delivery.delivered', $a, $due > 0 ? ['cashCollected' => Money::toFloat($due)] : null);

            return $a;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['data' => $this->row($result->fresh(['order.items', 'order.customer', 'zone']), false)]);
    }

    /** POST /rider/deliveries/{id}/fail {reason} */
    public function fail(Request $request, string $id)
    {
        $rider = $this->rider($request);
        $a = $this->own($request, $id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $closed = DB::transaction(function () use ($a, $data) {
            $a = DeliveryAssignment::lockForUpdate()->findOrFail($a->id);
            if (! $a->isOpen()) {
                return true;
            }
            $this->flow->failed($a, $data['reason']);

            return false;
        });
        if ($closed) {
            return $this->error('invalid_delivery_state', 'This delivery is already closed.');
        }
        $this->audit($request, $rider, 'delivery.failed', $a, ['reason' => $data['reason']]);

        return response()->json(['data' => $this->row($a->fresh(['order.items', 'order.customer', 'zone']), false)]);
    }

    /** POST /rider/sharing {on} — location is shared only while you carry an order, and stops by itself afterwards. */
    public function sharing(Request $request)
    {
        $rider = $this->rider($request);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        if ($data['on'] && ! $this->open($rider)->exists()) {
            return $this->error('no_open_delivery', 'Location sharing is available only while you have a delivery.');
        }
        $rider->update(['location_sharing' => (bool) $data['on']] + ($data['on'] ? [] : $this->clearLocation()));

        return response()->json(['data' => $this->profile($rider->fresh())]);
    }

    /** POST /rider/location {lat, lng} */
    public function location(Request $request)
    {
        $rider = $this->rider($request);
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        if (! $rider->location_sharing || ! $this->open($rider)->exists()) {
            return $this->error('not_sharing', 'Location sharing is off.', 409);
        }
        $rider->update(['last_lat' => round((float) $data['lat'], 6), 'last_lng' => round((float) $data['lng'], 6), 'last_located_at' => now()]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** GET /rider/cash — what this rider is holding for the restaurant. */
    public function cash(Request $request)
    {
        $rider = $this->rider($request);
        $rows = RiderCashCollection::with('order:id,code')->where('rider_id', $rider->id)->whereNull('settled_at')->orderBy('collected_at')->get();

        return response()->json(['data' => [
            'holding' => Money::toFloat($rows->sum(fn ($r) => Money::toMinor($r->amount))),
            'items' => $rows->map(fn ($r) => ['orderCode' => $r->order?->code, 'amount' => (float) $r->amount, 'collectedAt' => $r->collected_at]),
        ]]);
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function rider(Request $request): Rider
    {
        return $request->attributes->get('rider');
    }

    private function open(Rider $rider)
    {
        return DeliveryAssignment::where('rider_id', $rider->id)->whereIn('status', ['assigned', 'picked_up']);
    }

    private function own(Request $request, string $id): DeliveryAssignment
    {
        return DeliveryAssignment::with(['order.items', 'order.customer', 'zone'])->where('id', $id)->where('rider_id', $this->rider($request)->id)->firstOrFail();
    }

    private function clearLocation(): array
    {
        return ['location_sharing' => false, 'last_lat' => null, 'last_lng' => null, 'last_located_at' => null];
    }

    private function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    private function audit(Request $request, Rider $rider, string $action, DeliveryAssignment $a, ?array $extra = null): void
    {
        AuditLog::record(
            actorId: $request->user()->id, actorName: $request->user()->name, action: $action, subjectType: DeliveryAssignment::class,
            subjectId: $a->id, branchId: $a->branch_id, changes: ['by' => 'rider_app', 'riderId' => $rider->id] + ($extra ?? []), ipAddress: $request->ip(),
        );
    }

    private function profile(Rider $r): array
    {
        $holding = RiderCashCollection::where('rider_id', $r->id)->whereNull('settled_at')->get()->sum(fn ($c) => Money::toMinor($c->amount));

        return [
            'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'vehicleType' => $r->vehicle_type, 'status' => $r->status,
            'locationSharing' => (bool) $r->location_sharing, 'openDeliveries' => $this->open($r)->count(), 'cashHolding' => Money::toFloat($holding),
        ];
    }

    /** The customer's name/phone and the exact amount to collect are shown only while the delivery is open. */
    private function row(DeliveryAssignment $a, bool $openView): array
    {
        $o = $a->order;
        $due = $openView && $o ? $this->ledger->balanceMinor($o) : 0;
        $address = $a->delivery_address;

        return [
            'id' => $a->id, 'status' => $a->status, 'orderCode' => $o?->code, 'orderStatus' => $o?->status, 'cancelled' => $o?->status === 'cancelled',
            'address' => $address, 'zone' => $a->zone?->name,
            'mapUrl' => $address ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address) : null,
            'customerName' => $openView ? $o?->customer?->name : null,
            'customerPhone' => $openView ? ($o?->delivery_phone ?: $o?->customer?->phone) : null,
            'items' => $o ? $o->items->map(fn ($i) => ['name' => $i->name, 'quantity' => (int) $i->quantity, 'notes' => $i->notes])->values() : [],
            'cashToCollect' => Money::toFloat($due), 'total' => $o ? (float) $o->grand_total : null,
            'assignedAt' => $a->assigned_at, 'pickedUpAt' => $a->picked_up_at, 'deliveredAt' => $a->delivered_at, 'failedAt' => $a->failed_at, 'failureReason' => $a->failure_reason,
        ];
    }
}
