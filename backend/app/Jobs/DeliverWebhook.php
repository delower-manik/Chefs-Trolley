<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\Webhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/** POSTs a signed event. Retries with backoff (notify.webhooks.backoff_minutes); receivers dedupe on the event id. */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        $d = WebhookDelivery::with('webhook')->find($this->deliveryId);
        if (! $d || $d->status === 'sent' || ! $d->webhook || ! $d->webhook->is_active) {
            return;
        }

        $d->increment('attempts');

        if ($problem = Webhooks::urlProblem($d->webhook->url)) {
            $d->update(['status' => 'failed', 'error' => $problem, 'next_attempt_at' => null]); // not retryable

            return;
        }

        $ts = time();
        try {
            $r = Http::timeout((int) config('notify.webhooks.timeout', 8))->withoutRedirecting()->withBody($d->payload, 'application/json')
                ->withHeaders([
                    'User-Agent' => 'ChefsTrolley-Webhooks/1',
                    'X-ChefsTrolley-Event' => $d->event,
                    'X-ChefsTrolley-Delivery' => $d->event_id,
                    'X-ChefsTrolley-Signature' => Webhooks::signature($d->webhook->secret, $d->payload, $ts),
                ])->post($d->webhook->url);

            $status = $r->status();
            $excerpt = substr($r->body(), 0, 300);
            $ok = $r->successful();
            $error = $ok ? null : "HTTP {$status}";
        } catch (\Throwable $e) {
            $status = null;
            $excerpt = null;
            $ok = false;
            $error = 'connection: '.substr($e->getMessage(), 0, 200);
        }

        if ($ok) {
            $d->update(['status' => 'sent', 'response_status' => $status, 'response_excerpt' => $excerpt, 'error' => null,
                'delivered_at' => now(), 'next_attempt_at' => null]);

            return;
        }

        $backoff = (array) config('notify.webhooks.backoff_minutes', [1, 5, 30, 120, 360]);
        $retryable = $status === null || $status >= 500 || in_array($status, [408, 429], true);
        if ($retryable && $d->attempts < $this->tries) {
            $minutes = $backoff[min($d->attempts - 1, count($backoff) - 1)];
            $d->update(['response_status' => $status, 'response_excerpt' => $excerpt, 'error' => $error, 'next_attempt_at' => now()->addMinutes($minutes)]);
            $this->release($minutes * 60);

            return;
        }

        $d->update(['status' => 'failed', 'response_status' => $status, 'response_excerpt' => $excerpt, 'error' => $error, 'next_attempt_at' => null]);
    }

    public function failed(\Throwable $e): void
    {
        WebhookDelivery::where('id', $this->deliveryId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 300)]);
    }
}
