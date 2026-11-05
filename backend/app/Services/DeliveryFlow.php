<?php

namespace App\Services;

use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\Rider;

/**
 * The delivery state changes, in ONE place, shared by the dispatcher board and the rider app so the two can never
 * drift apart. Callers check permissions/ownership and the transition itself; this only applies the change.
 */
class DeliveryFlow
{
    public function pickedUp(DeliveryAssignment $a, Order $order): void
    {
        $a->update(['status' => 'picked_up', 'picked_up_at' => now()]);
        $order->update(['status' => 'picked_up']);
    }

    public function delivered(DeliveryAssignment $a, Order $order, ?string $recipient, ?string $note): void
    {
        $a->update(['status' => 'delivered', 'delivered_at' => now(), 'recipient_name' => $recipient, 'proof_note' => $note]);
        $order->update(['status' => 'delivered']);
        $this->releaseRiderIfFree(null, $a->rider_id);
    }

    public function failed(DeliveryAssignment $a, string $reason): void
    {
        $a->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => $reason]);
        $this->releaseRiderIfFree(null, $a->rider_id);
    }

    /**
     * A rider with no other open delivery becomes "available" again. Location sharing ends with the last delivery and
     * the stored position is wiped: we only follow a rider while they are carrying an order.
     */
    public function releaseRiderIfFree(?string $previousRiderId, ?string $currentRiderId): void
    {
        foreach (array_unique(array_filter([$previousRiderId, $currentRiderId])) as $riderId) {
            $stillBusy = DeliveryAssignment::where('rider_id', $riderId)->whereIn('status', ['assigned', 'picked_up'])->exists();
            if (! $stillBusy) {
                Rider::whereKey($riderId)->update(['status' => 'available', 'location_sharing' => false, 'last_lat' => null, 'last_lng' => null, 'last_located_at' => null]);
            }
        }
    }
}
