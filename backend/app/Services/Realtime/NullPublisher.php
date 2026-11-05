<?php

namespace App\Services\Realtime;

class NullPublisher implements Publisher
{
    public function publish(string $branchId, string $event, array $data): void {}

    public function publishOrder(string $orderId, string $event, array $data): void {}

    public function clientConfig(): array
    {
        return ['driver' => 'none'];
    }
}
