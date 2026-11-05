<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Pusher Channels REST API (also Reverb, which implements the same protocol). No SDK needed. */
class PusherPublisher implements Publisher
{
    public function __construct(private array $cfg, private int $timeout = 2) {}

    public static function channel(string $branchId): string
    {
        return 'private-branch.'.$branchId;
    }

    public static function orderChannel(string $orderId): string
    {
        return 'private-order.'.$orderId;
    }

    public function publish(string $branchId, string $event, array $data): void
    {
        $this->send(self::channel($branchId), $event, $data);
    }

    public function publishOrder(string $orderId, string $event, array $data): void
    {
        $this->send(self::orderChannel($orderId), $event, $data);
    }

    private function send(string $channel, string $event, array $data): void
    {
        try {
            $path = '/apps/'.$this->cfg['app_id'].'/events';
            $body = json_encode([
                'name' => $event,
                'channels' => [$channel],
                'data' => json_encode($data),
            ]);
            $query = $this->signedQuery('POST', $path, $body);

            $response = Http::timeout($this->timeout)->withBody($body, 'application/json')->post($this->baseUrl().$path.'?'.$query);
            if (! $response->successful()) {
                Log::warning('realtime.pusher_failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 200)]);
            }
        } catch (\Throwable $e) {
            Log::warning('realtime.pusher_error', ['error' => $e->getMessage()]);
        }
    }

    /** Pusher request signing: HMAC-SHA256 over "METHOD\npath\nsorted-query". */
    public function signedQuery(string $method, string $path, string $body, ?int $timestamp = null): string
    {
        $params = [
            'auth_key' => $this->cfg['key'],
            'auth_timestamp' => (string) ($timestamp ?? time()),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($params);
        $query = urldecode(http_build_query($params));
        $signature = hash_hmac('sha256', "{$method}\n{$path}\n{$query}", $this->cfg['secret']);

        return $query.'&auth_signature='.$signature;
    }

    /** Private-channel subscription auth: "key:HMAC(secret, socket_id:channel)". */
    public function authSignature(string $socketId, string $channel): string
    {
        return $this->cfg['key'].':'.hash_hmac('sha256', "{$socketId}:{$channel}", $this->cfg['secret']);
    }

    public function clientConfig(): array
    {
        $host = $this->cfg['host'];

        return array_filter([
            'driver' => 'pusher',
            'key' => $this->cfg['key'],
            'cluster' => $this->cfg['cluster'],
            'wsHost' => $host ?: null,
            'wsPort' => $this->cfg['port'] ? (int) $this->cfg['port'] : null,
            'forceTLS' => ($this->cfg['scheme'] ?? 'https') === 'https',
        ], fn ($v) => $v !== null);
    }

    private function baseUrl(): string
    {
        if ($this->cfg['host']) {
            $port = $this->cfg['port'] ? ':'.$this->cfg['port'] : '';

            return ($this->cfg['scheme'] ?? 'https').'://'.$this->cfg['host'].$port;
        }

        return 'https://api-'.$this->cfg['cluster'].'.pusher.com';
    }
}
