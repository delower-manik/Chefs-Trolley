<?php

namespace App\Services\Notify;

use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\User;
use App\Services\AppSettings;

/** Tells the waiter who took an order that it is ready to serve. Push only; no creator or no app installed means nothing is sent. */
class StaffAlerts
{
    public function __construct(private Notifier $notifier) {}

    public function orderReady(Order $order): void
    {
        if (! $order->created_by) {
            return;
        }
        $locale = (string) app(AppSettings::class)->get('locale.default', 'en');
        $locale = in_array($locale, ['en', 'bn'], true) ? $locale : 'en';
        $where = $order->diningTable?->label ? ' ('.$order->diningTable->label.')' : '';
        $body = __('staff.ready_body', ['code' => $order->code, 'where' => $where], $locale);
        $title = __('staff.ready_title', [], $locale);
        foreach (DeviceToken::where('owner_type', User::class)->where('owner_id', $order->created_by)->pluck('token') as $token) {
            $this->notifier->queue('push', $token, $body, $title, 'order_ready', $order->branch_id, null, Order::class, $order->id);
        }
    }
}
