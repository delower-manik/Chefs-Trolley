<?php

namespace App\Support;

use App\Models\DeliveryAssignment;
use App\Models\Order;

/** The customer-facing progress of an order. One definition, used by the API and by the live-tracking ping. */
class OrderStage
{
    public static function of(Order $o, ?DeliveryAssignment $assignment = null): string
    {
        $assignment ??= $o->source === 'delivery' ? DeliveryAssignment::where('order_id', $o->id)->first() : null;

        return match (true) {
            $o->status === 'cancelled' => 'cancelled',
            in_array($o->status, ['completed', 'delivered'], true) => 'done',
            $assignment?->status === 'picked_up' => 'on_the_way',
            $o->kitchen_state === 'ready' => 'ready',
            in_array($o->kitchen_state, ['preparing', 'accepted'], true) => 'preparing',
            in_array($o->kitchen_state, ['served', 'completed'], true) => 'ready',
            default => 'received',
        };
    }
}
