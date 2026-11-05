<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Ably REST publish + signed TokenRequests for browsers (the API key never reaches the client). */
class AblyPublisher implements Publisher
{
    public function __construct(private array $cfg, private int $timeout = 2) {}

    public static function channel(string $branchId): string
    {
        return 'branch:'.$branchId;
    }

    /** Ably has no customer-order channel here: the website polls instead. */
    public function publishOrder(string $orderId, string $event, array $data): void {}

    public function publish(string $branchId, string $event, array $data): void
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->keyName(), $this->keySecret())
                ->post(rtrim($this->cfg['rest_url'], '/').'/channels/'.rawurlencode(self::channel($branchId)).'/messages', [
                    'name' => $event,
                    'data' => $data,
                ]);
            if (! $response->successful()) {
                Log::warning('realtime.ably_failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 200)]);
            }
        } catch (\Throwable $e) {
            Log::warning('realtime.ably_error', ['error' => $e->getMessage()]);
        }
    }

    /** A TokenRequest the browser exchanges with Ably; capability is limited to subscribing to one branch. */
    public function tokenRequest(string $branchId, string $clientId, ?int $nowMs = null, ?string $nonce = null): array
    {
        $ttl = 3600 * 1000;
        $timestamp = $nowMs ?? (int) (microtime(true) * 1000);
        $nonce ??= bin2hex(random_bytes(8));
        $capability = json_encode([self::channel($branchId) => ['subscribe']]);
        $signText = implode("\n", [$this->keyName(), $ttl, $capability, $clientId, $timestamp, $nonce])."\n";

        return [
            'keyName' => $this->keyName(),
            'ttl' => $ttl,
            'capability' => $capability,
            'clientId' => $clientId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'mac' => base64_encode(hash_hmac('sha256', $signText, $this->keySecret(), true)),
        ];
    }

    public function clientConfig(): array
    {
        return ['driver' => 'ably'];
    }

    private function keyName(): string
    {
        return explode(':', (string) $this->cfg['key'], 2)[0];
    }

    private function keySecret(): string
    {
        return explode(':', (string) $this->cfg['key'], 2)[1] ?? '';
    }
}
