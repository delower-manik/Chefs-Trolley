<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Webhooks
{
    /** Queues one signed delivery per active subscriber of $event. Never throws into the caller. */
    public static function fire(string $event, array $data): void
    {
        try {
            $eventId = (string) Str::uuid();
            $payload = json_encode([
                'id' => $eventId, 'event' => $event, 'createdAt' => now()->toIso8601String(), 'data' => $data,
            ]);

            foreach (Webhook::where('event', $event)->where('is_active', true)->get() as $webhook) {
                $delivery = WebhookDelivery::create([
                    'webhook_id' => $webhook->id, 'event' => $event, 'event_id' => $eventId, 'payload' => $payload,
                ]);
                DeliverWebhook::dispatch($delivery->id);
            }
        } catch (\Throwable $e) {
            Log::warning('webhooks.fire_failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** Signature header: "t=<unix>,v1=<hex hmac-sha256 of "<t>.<body>">". Receivers should reject old timestamps. */
    public static function signature(string $secret, string $body, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /** SSRF guard: https only and a public address, unless explicitly relaxed for local development. */
    public static function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! $host || ! in_array($scheme, ['http', 'https'], true)) {
            return 'Webhook URL must be http(s).';
        }
        if (config('notify.webhooks.allow_insecure')) {
            return null;
        }
        if ($scheme !== 'https') {
            return 'Webhook URLs must use https.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return 'Webhook host could not be resolved.';
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook URLs must point to a public address.';
            }
        }

        return null;
    }
}
