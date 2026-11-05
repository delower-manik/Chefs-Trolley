<?php

namespace App\Services\Notify;

use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Services\AppSettings;
use App\Support\Bn;

/** Transactional messages to the customer about their own online order. Off unless NOTIFY_ORDER_SMS / _PUSH are set. */
class OrderNotifier
{
    private const SOURCES = ['delivery', 'takeaway', 'app_web'];

    public function __construct(private Notifier $notifier) {}

    public function orderChanged(Order $order, bool $created): void
    {
        if (! $order->customer_id || ! in_array($order->source, self::SOURCES, true) || $order->status === 'draft') {
            return;
        }
        $sms = (bool) config('notify.order_sms');
        $push = (bool) config('notify.order_push');
        if (! $sms && ! $push) {
            return;
        }

        $customer = Customer::find($order->customer_id);
        // The customer's own language if they chose one, otherwise the business default.
        $locale = in_array($customer?->locale, ['en', 'bn'], true) ? $customer->locale : (string) app(AppSettings::class)->get('locale.default', 'en');
        $message = $customer ? $this->message($order, $created, $locale) : null;
        if (! $customer || ! $message) {
            return;
        }

        if ($sms && $customer->phone) {
            $this->notifier->queue('sms', $customer->phone, $message, null, 'order_status', $order->branch_id, $customer->id, Order::class, $order->id);
        }
        if ($push) {
            foreach (DeviceToken::where('owner_type', Customer::class)->where('owner_id', $customer->id)->pluck('token') as $token) {
                $this->notifier->queue('push', $token, $message, __('notify.push_title', ['code' => $order->code], $locale), 'order_status', $order->branch_id, $customer->id, Order::class, $order->id);
            }
        }
    }

    private function message(Order $order, bool $created, string $locale): ?string
    {
        $r = ['code' => Bn::localise($order->code, $locale)];
        $t = fn (string $key, array $extra = []) => __('notify.'.$key, $r + $extra, $locale);
        if ($created) {
            $settings = app(AppSettings::class);

            return $t('order_received', ['currency' => $settings->get('currency.code', 'BDT'), 'total' => Bn::localise(number_format((float) $order->grand_total, 2), $locale)]);
        }

        return match ($order->status) {
            'accepted' => $t('order_accepted'),
            'ready' => $t($order->source === 'delivery' ? 'order_ready_delivery' : 'order_ready_pickup'),
            'picked_up' => $t('order_picked_up'),
            'delivered' => $t('order_delivered'),
            'cancelled' => $t('order_cancelled'),
            default => null,
        };
    }
}
