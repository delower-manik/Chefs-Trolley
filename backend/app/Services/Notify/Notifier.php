<?php

namespace App\Services\Notify;

use App\Jobs\SendNotificationDelivery;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Log;

/** Creates a delivery row and queues it. Never throws into the caller: a notification must not break a sale. */
class Notifier
{
    public function queue(
        string $channel,
        string $recipient,
        string $body,
        ?string $subject = null,
        string $purpose = 'custom',
        ?string $branchId = null,
        ?string $customerId = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        bool $immediate = false, // send in this request (e.g. a login code can't wait for the next cron tick)
    ): ?NotificationDelivery {
        try {
            $delivery = NotificationDelivery::create([
                'branch_id' => $branchId, 'channel' => $channel, 'recipient' => $recipient, 'customer_id' => $customerId,
                'subject' => $subject, 'body' => $body, 'purpose' => $purpose,
                'reference_type' => $referenceType, 'reference_id' => $referenceId,
            ]);
            $immediate ? SendNotificationDelivery::dispatchSync($delivery->id) : SendNotificationDelivery::dispatch($delivery->id);

            return $delivery;
        } catch (\Throwable $e) {
            Log::warning('notify.queue_failed', ['channel' => $channel, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
