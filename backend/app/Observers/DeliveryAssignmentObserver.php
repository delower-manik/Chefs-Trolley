<?php

namespace App\Observers;

use App\Models\DeliveryAssignment;
use App\Services\Realtime\Realtime;
use App\Support\OrderStage;

class DeliveryAssignmentObserver
{
    public bool $afterCommit = true;

    public function saved(DeliveryAssignment $assignment): void
    {
        $order = $assignment->order;
        if ($order && $order->channel === 'web') {
            Realtime::publisher()->publishOrder($order->id, 'order.updated', ['stage' => OrderStage::of($order, $assignment)]);
        }
        $branchId = $order?->branch_id;
        if (! $branchId) {
            return;
        }
        Realtime::publisher()->publish($branchId, 'delivery.changed', [
            'id' => $assignment->id, 'orderId' => $assignment->order_id, 'status' => $assignment->status, 'riderId' => $assignment->rider_id,
        ]);
    }
}
