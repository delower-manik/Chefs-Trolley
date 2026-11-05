<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Log;

class LogPublisher implements Publisher
{
    public function publish(string $branchId, string $event, array $data): void
    {
        Log::info('realtime', ['branch' => $branchId, 'event' => $event, 'data' => $data]);
    }

    public function publishOrder(string $orderId, string $event, array $data): void
    {
        Log::info('realtime.order', ['order' => $orderId, 'event' => $event, 'data' => $data]);
    }

    public function clientConfig(): array
    {
        return ['driver' => 'none'];
    }
}
