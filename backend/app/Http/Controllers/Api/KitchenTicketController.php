<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\Notify\StaffAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KitchenTicketController extends Controller
{
    /**
     * Forward-only kitchen state machine (architecture §9):
     * new -> accepted -> preparing -> ready -> served -> completed.
     * "Reopen" is the one allowed backward move, and only one step back,
     * for correcting an accidental bump.
     *
     * State lives on each order line (so a Grill station and a Bar station
     * advance their own part of a ticket independently); the order's
     * kitchen_state is always the least-advanced line.
     */
    private const STATES = ['new', 'accepted', 'preparing', 'ready', 'served', 'completed'];

    private const FORWARD_TRANSITIONS = [
        'new' => 'accepted',
        'accepted' => 'preparing',
        'preparing' => 'ready',
        'ready' => 'served',
        'served' => 'completed',
    ];

    private const REOPEN_TRANSITIONS = [
        'accepted' => 'new',
        'preparing' => 'accepted',
        'ready' => 'preparing',
        'served' => 'ready',
    ];

    /**
     * GET /kitchen/tickets[?station_id=<uuid>|none]
     * Every order still in the kitchen's active window, oldest first so the
     * board reads as a queue. With station_id, only that station's lines are
     * shown (and only orders where that station still has work); "none"
     * means lines that are not routed to any station. Without it, the whole
     * order is shown (the original all-in-one board).
     */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $stationFilter = $request->query('station_id');

        $orders = Order::query()->with(['items.kitchenStation'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('status', ['draft', 'cancelled', 'refunded'])
            ->whereIn('kitchen_state', ['new', 'accepted', 'preparing', 'ready'])
            ->orderBy('created_at')
            ->get();

        $tickets = $orders->map(function (Order $order) use ($stationFilter) {
            $items = $this->scope($order, $stationFilter);
            if ($items->isEmpty()) {
                // No lines: only the unfiltered board shows it (legacy/item-less orders).
                return $stationFilter ? null : $this->serialize($order, $items, $order->kitchen_state);
            }
            $state = $this->minState($items->pluck('kitchen_state'));
            if (! in_array($state, ['new', 'accepted', 'preparing', 'ready'], true)) {
                return null; // this station has finished its part
            }

            return $this->serialize($order, $items, $stationFilter ? $state : $order->kitchen_state);
        })->filter()->values();

        return response()->json(['data' => $tickets]);
    }

    /**
     * POST /kitchen/tickets/{order}/bump[?station_id=]
     * Advances the ticket one step. With station_id only that station's lines
     * move; otherwise every line moves. "ready" also flips the order's overall
     * status so front-of-house sees it without polling a second endpoint.
     */
    public function bump(Request $request, Order $order)
    {
        $stationFilter = $request->input('station_id', $request->query('station_id'));
        $order->load('items');
        $scope = $this->scope($order, $stationFilter);

        if ($stationFilter && $scope->isEmpty()) {
            return response()->json(['code' => 'no_items_for_station', 'message' => 'This ticket has no items for that station.'], 422);
        }

        $current = $stationFilter ? $this->minState($scope->pluck('kitchen_state')) : $order->kitchen_state;
        $next = self::FORWARD_TRANSITIONS[$current] ?? null;

        if (! $next) {
            return response()->json([
                'code' => 'invalid_kitchen_state',
                'message' => "Ticket {$order->code} is already at its final kitchen state.",
            ], 422);
        }

        $before = $order->kitchen_state;
        DB::transaction(function () use ($order, $scope, $next) {
            foreach ($scope as $item) {
                if ($this->rank($item->kitchen_state) < $this->rank($next)) {
                    $item->update(['kitchen_state' => $next]);
                }
            }
            if ($order->items->isEmpty()) {
                // Item-less order (legacy data): move the order itself.
                $order->kitchen_state = $next;
                if (in_array($next, ['accepted', 'preparing', 'ready', 'completed'], true)) {
                    $order->status = $next;
                }
                $order->save();
                if ($next === 'completed') {
                    $order->diningTable?->releaseIfIdle();
                }

                return;
            }
            $this->syncOrderState($order->fresh('items'), syncStatus: true);
        });

        $fresh = $order->fresh(['diningTable']);
        $this->audit($request, $order, 'kitchen_ticket.bumped', $before, $fresh->kitchen_state, $stationFilter);
        if ($before !== 'ready' && $fresh->kitchen_state === 'ready') {
            app(StaffAlerts::class)->orderReady($fresh); // the whole order just became ready: tell the waiter who took it
        }

        return response()->json(['data' => $this->ticketFor($order->fresh('items.kitchenStation'), $stationFilter)]);
    }

    /**
     * POST /kitchen/tickets/{order}/reopen[?station_id=]
     * Corrects an accidental bump by moving one step backward.
     */
    public function reopen(Request $request, Order $order)
    {
        $stationFilter = $request->input('station_id', $request->query('station_id'));
        $order->load('items');
        $scope = $this->scope($order, $stationFilter);

        if ($stationFilter && $scope->isEmpty()) {
            return response()->json(['code' => 'no_items_for_station', 'message' => 'This ticket has no items for that station.'], 422);
        }

        $current = $stationFilter ? $this->maxState($scope->pluck('kitchen_state')) : $order->kitchen_state;
        $previous = self::REOPEN_TRANSITIONS[$current] ?? null;

        if (! $previous) {
            return response()->json([
                'code' => 'invalid_kitchen_state',
                'message' => "Ticket {$order->code} can't be reopened from its current state.",
            ], 422);
        }

        $before = $order->kitchen_state;
        DB::transaction(function () use ($order, $scope, $previous) {
            foreach ($scope as $item) {
                if ($this->rank($item->kitchen_state) > $this->rank($previous)) {
                    $item->update(['kitchen_state' => $previous]);
                }
            }
            if ($order->items->isEmpty()) {
                $order->update(['kitchen_state' => $previous]);

                return;
            }
            $this->syncOrderState($order->fresh('items'), syncStatus: false);
        });

        $this->audit($request, $order, 'kitchen_ticket.reopened', $before, $order->fresh()->kitchen_state, $stationFilter);

        return response()->json(['data' => $this->ticketFor($order->fresh('items.kitchenStation'), $stationFilter)]);
    }

    private function ticketFor(Order $order, ?string $stationFilter): array
    {
        $items = $this->scope($order, $stationFilter);
        $state = $stationFilter && $items->isNotEmpty() ? $this->minState($items->pluck('kitchen_state')) : $order->kitchen_state;

        return $this->serialize($order, $items, $state);
    }

    /** Lines a station is responsible for (all lines when no station is given). */
    private function scope(Order $order, ?string $stationFilter): Collection
    {
        if (! $stationFilter) {
            return $order->items;
        }

        return $order->items->filter(
            fn ($item) => $stationFilter === 'none' ? $item->kitchen_station_id === null : $item->kitchen_station_id === $stationFilter
        )->values();
    }

    /** order.kitchen_state = least-advanced line; mirrors it onto status where there is a counterpart. */
    private function syncOrderState(Order $order, bool $syncStatus): void
    {
        if ($order->items->isEmpty()) {
            return;
        }

        $state = $this->minState($order->items->pluck('kitchen_state'));
        $changed = $state !== $order->kitchen_state;
        $order->kitchen_state = $state;

        // Kitchen state and overall order status are tracked separately
        // (different order types finish differently -- dine-in ends at
        // "served", delivery continues through assigned/picked_up/delivered)
        // but we keep them in sync wherever there's a direct counterpart.
        if ($syncStatus && $changed && in_array($state, ['accepted', 'preparing', 'ready', 'completed'], true)) {
            $order->status = $state;
        }
        $order->save();

        if ($state === 'completed') {
            $order->diningTable?->releaseIfIdle();
        }
    }

    private function rank(string $state): int
    {
        return (int) array_search($state, self::STATES, true);
    }

    private function minState(Collection $states): string
    {
        return self::STATES[$states->map(fn ($s) => $this->rank($s))->min()];
    }

    private function maxState(Collection $states): string
    {
        return self::STATES[$states->map(fn ($s) => $this->rank($s))->max()];
    }

    private function audit(Request $request, Order $order, string $action, string $before, string $after, ?string $station): void
    {
        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $action,
            subjectType: Order::class,
            subjectId: $order->id,
            branchId: $order->branch_id,
            changes: ['before' => $before, 'after' => $after, 'station' => $station],
            ipAddress: $request->ip(),
        );
    }

    private function serialize(Order $order, Collection $items, string $state): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'source' => $order->source,
            'diningTableId' => $order->dining_table_id,
            'tableLabel' => $order->diningTable?->label,
            'kitchenState' => $state,
            'status' => $order->status,
            'createdAt' => $order->created_at,
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'modifiers' => $item->modifiers ?? [],
                'notes' => $item->notes,
                'comboName' => $item->combo_name,
                'kitchenState' => $item->kitchen_state,
                'stationId' => $item->kitchen_station_id,
                'stationName' => $item->kitchenStation?->name,
            ])->values(),
        ];
    }
}
