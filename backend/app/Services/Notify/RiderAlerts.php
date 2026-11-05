<?php

namespace App\Services\Notify;

use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use App\Services\AppSettings;

/** Tells a rider's phone about a new delivery. Push only (no SMS): a rider without the app installed simply isn't pushed. */
class RiderAlerts
{
    public function __construct(private Notifier $notifier) {}

    public function assigned(Rider $rider, Order $order, ?string $address): void
    {
        if (! $rider->user_id) {
            return;
        }
        $locale = (string) app(AppSettings::class)->get('locale.default', 'en');
        $locale = in_array($locale, ['en', 'bn'], true) ? $locale : 'en';
        $body = __('rider.assigned_body', ['code' => $order->code, 'address' => mb_substr((string) $address, 0, 80)], $locale);
        $title = __('rider.assigned_title', [], $locale);
        foreach (DeviceToken::where('owner_type', User::class)->where('owner_id', $rider->user_id)->pluck('token') as $token) {
            $this->notifier->queue('push', $token, $body, $title, 'delivery_assigned', $order->branch_id, null, Order::class, $order->id);
        }
    }
}
