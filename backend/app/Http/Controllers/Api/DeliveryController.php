<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignDeliveryRequest;
use App\Http\Requests\DeliverOrderRequest;
use App\Http\Requests\FailDeliveryRequest;
use App\Models\AuditLog;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\Rider;
use App\Services\DeliveryFlow;
use App\Services\Notify\RiderAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The dispatch board: assigning riders to delivery orders and tracking
 * each handoff. The order's own `status` remains the source of truth for
 * the general lifecycle (assigned/picked_up/delivered are existing Order
 * statuses since Phase 1) -- this controller keeps `delivery_assignments`
 * in sync alongside it rather than replacing it.
 */
class DeliveryController extends Controller
{
    /**
     * GET /deliveries
     * The dispatch board feed -- every delivery-order assignment for the
     * branch, newest first, optionally filtered by its delivery-specific
     * status.
     */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $assignments = DeliveryAssignment::query()->with(['order', 'rider', 'zone'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $assignments->map($this->serialize(...))]);
    }

    /**
     * POST /deliveries/{order}/assign
     * Assigns (or reassigns, before pickup) a rider. Allowed from
     * "pending", "assigned" (swap riders), or "failed" (retry after a
     * failed attempt) -- not from "picked_up" or "delivered", where the
     * handoff has already moved past the point reassignment makes sense.
     */
    public function assign(AssignDeliveryRequest $request, Order $order)
    {
        if ($order->source !== 'delivery') {
            return response()->json(['code' => 'not_a_delivery_order', 'message' => 'This order is not a delivery order.'], 422);
        }

        $assignment = $order->deliveryAssignment ?? DeliveryAssignment::create([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'status' => 'pending',
        ]);

        if (! in_array($assignment->status, ['pending', 'assigned', 'failed'], true)) {
            return response()->json([
                'code' => 'invalid_delivery_state',
                'message' => "This delivery is already \"{$assignment->status}\" and can't be reassigned.",
            ], 422);
        }

        $rider = Rider::findOrFail($request->validated('rider_id'));
        if (! $rider->is_active) {
            return response()->json(['code' => 'rider_inactive', 'message' => 'This rider is not active.'], 422);
        }
        if ($rider->branch_id !== $order->branch_id) {
            return response()->json(['code' => 'rider_wrong_branch', 'message' => 'This rider belongs to a different branch.'], 422);
        }

        $previousRiderId = $assignment->rider_id;

        DB::transaction(function () use ($request, $assignment, $order, $rider) {
            $assignment->update([
                'rider_id' => $rider->id,
                'delivery_zone_id' => $request->validated('delivery_zone_id') ?? $assignment->delivery_zone_id,
                'delivery_address' => $request->validated('delivery_address') ?? $assignment->delivery_address,
                'status' => 'assigned',
                'assigned_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);

            $rider->update(['status' => 'busy']);

            // Order status only ever moves forward through the delivery
            // lifecycle -- reassigning after a failed attempt shouldn't
            // regress an order that (for whatever other reason) has since
            // moved on.
            if (in_array($order->status, ['pending', 'confirmed', 'accepted', 'preparing', 'ready'], true)) {
                $order->update(['status' => 'assigned']);
            }
        });

        $this->releaseRiderIfFree($previousRiderId, $rider->id);
        app(RiderAlerts::class)->assigned($rider, $order, $assignment->delivery_address);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'delivery.assigned',
            subjectType: DeliveryAssignment::class,
            subjectId: $assignment->id,
            branchId: $assignment->branch_id,
            changes: ['riderId' => $rider->id, 'riderName' => $rider->name],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($assignment->fresh(['order', 'rider', 'zone']))]);
    }

    /**
     * POST /deliveries/{order}/pickup
     */
    public function pickup(Request $request, Order $order)
    {
        $assignment = $order->deliveryAssignment;
        if (! $assignment || ! $assignment->canTransitionTo('picked_up')) {
            return response()->json([
                'code' => 'invalid_delivery_state',
                'message' => 'This delivery must be assigned to a rider before it can be picked up.',
            ], 422);
        }

        app(DeliveryFlow::class)->pickedUp($assignment, $order);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'delivery.picked_up',
            subjectType: DeliveryAssignment::class,
            subjectId: $assignment->id,
            branchId: $assignment->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($assignment->fresh(['order', 'rider', 'zone']))]);
    }

    /**
     * POST /deliveries/{order}/deliver
     */
    public function deliver(DeliverOrderRequest $request, Order $order)
    {
        $assignment = $order->deliveryAssignment;
        if (! $assignment || ! $assignment->canTransitionTo('delivered')) {
            return response()->json([
                'code' => 'invalid_delivery_state',
                'message' => 'This delivery must be picked up before it can be marked delivered.',
            ], 422);
        }

        app(DeliveryFlow::class)->delivered($assignment, $order, $request->validated('recipient_name'), $request->validated('proof_note'));

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'delivery.delivered',
            subjectType: DeliveryAssignment::class,
            subjectId: $assignment->id,
            branchId: $assignment->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($assignment->fresh(['order', 'rider', 'zone']))]);
    }

    /**
     * POST /deliveries/{order}/fail
     * Marks this delivery attempt failed and frees up the rider. The order
     * itself is deliberately left as-is: a dispatcher decides next --
     * reassign a fresh attempt via `assign` again, or cancel the order
     * through the existing Orders cancel flow if it's being scrapped
     * entirely. Auto-deciding that would be guessing at a business call
     * this system shouldn't make silently.
     */
    public function fail(FailDeliveryRequest $request, Order $order)
    {
        $assignment = $order->deliveryAssignment;
        if (! $assignment || ! $assignment->isOpen()) {
            return response()->json([
                'code' => 'invalid_delivery_state',
                'message' => 'This delivery is not currently open.',
            ], 422);
        }

        app(DeliveryFlow::class)->failed($assignment, $request->validated('reason'));

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'delivery.failed',
            subjectType: DeliveryAssignment::class,
            subjectId: $assignment->id,
            branchId: $assignment->branch_id,
            changes: ['reason' => $request->validated('reason')],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($assignment->fresh(['order', 'rider', 'zone']))]);
    }

    private function releaseRiderIfFree(?string $previousRiderId, ?string $currentRiderId): void
    {
        app(DeliveryFlow::class)->releaseRiderIfFree($previousRiderId, $currentRiderId);
    }

    private function serialize(DeliveryAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'orderId' => $assignment->order_id,
            'orderCode' => $assignment->order?->code,
            'orderGrandTotal' => $assignment->order ? (float) $assignment->order->grand_total : null,
            'branchId' => $assignment->branch_id,
            'riderId' => $assignment->rider_id,
            'riderName' => $assignment->rider?->name,
            'zoneId' => $assignment->delivery_zone_id,
            'zoneName' => $assignment->zone?->name,
            'deliveryAddress' => $assignment->delivery_address,
            'status' => $assignment->status,
            'assignedAt' => $assignment->assigned_at,
            'pickedUpAt' => $assignment->picked_up_at,
            'deliveredAt' => $assignment->delivered_at,
            'failedAt' => $assignment->failed_at,
            'failureReason' => $assignment->failure_reason,
            'recipientName' => $assignment->recipient_name,
            'proofNote' => $assignment->proof_note,
            'createdAt' => $assignment->created_at,
        ];
    }
}
