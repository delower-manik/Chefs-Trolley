<?php

namespace App\Services\Realtime;

interface Publisher
{
    /** Pushes an event to everyone subscribed to a branch's channel. Must never throw. */
    public function publish(string $branchId, string $event, array $data): void;

    /**
     * A tiny "something changed" ping for ONE customer's order (website live tracking). Carries no order details:
     * the browser re-fetches through the authenticated API. Providers without customer channels do nothing and the
     * website falls back to polling. Must never throw.
     */
    public function publishOrder(string $orderId, string $event, array $data): void;

    /** Public settings a browser needs to connect (never secrets). */
    public function clientConfig(): array;
}
