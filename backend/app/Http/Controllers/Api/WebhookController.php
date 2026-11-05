<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveWebhookRequest;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Webhook subscriptions plus their delivery log. Events are queued and POSTed by App\Jobs\DeliverWebhook
 * (HMAC-signed, retried with backoff); see App\Services\Webhooks\Webhooks.
 */
class WebhookController extends Controller
{
    public function index()
    {
        $webhooks = Webhook::orderByDesc('created_at')->get();

        return response()->json(['data' => $webhooks->map($this->serialize(...))]);
    }

    public function store(SaveWebhookRequest $request)
    {
        if ($problem = Webhooks::urlProblem($request->validated('url'))) {
            return $this->urlError($problem);
        }
        $webhook = Webhook::create([
            ...$request->validated(),
            'secret' => Str::random(40),
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'webhook.created',
            subjectType: Webhook::class,
            subjectId: $webhook->id,
            changes: ['url' => $webhook->url, 'event' => $webhook->event],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($webhook, revealSecret: true)], 201);
    }

    public function update(SaveWebhookRequest $request, Webhook $webhook)
    {
        if ($request->has('url') && ($problem = Webhooks::urlProblem($request->validated('url')))) {
            return $this->urlError($problem);
        }
        $webhook->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'webhook.updated',
            subjectType: Webhook::class,
            subjectId: $webhook->id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($webhook->fresh())]);
    }

    public function destroy(Request $request, Webhook $webhook)
    {
        $webhook->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'webhook.deleted',
            subjectType: Webhook::class,
            subjectId: $webhook->id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    /** GET /webhooks/{webhook}/deliveries — most recent 50 attempts, for debugging a receiver. */
    public function deliveries(Webhook $webhook)
    {
        $rows = WebhookDelivery::where('webhook_id', $webhook->id)->latest()->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($d) => [
            'id' => $d->id, 'event' => $d->event, 'eventId' => $d->event_id, 'status' => $d->status, 'attempts' => $d->attempts,
            'responseStatus' => $d->response_status, 'responseExcerpt' => $d->response_excerpt, 'error' => $d->error,
            'nextAttemptAt' => $d->next_attempt_at?->toIso8601String(), 'deliveredAt' => $d->delivered_at?->toIso8601String(),
            'createdAt' => $d->created_at?->toIso8601String(),
        ])]);
    }

    /** POST /webhook-deliveries/{delivery}/redeliver — try again (same event id, so receivers can dedupe). */
    public function redeliver(Request $request, WebhookDelivery $delivery)
    {
        if ($delivery->status === 'sent') {
            return response()->json(['code' => 'already_delivered', 'message' => 'This event was already delivered.'], 422);
        }
        $delivery->update(['status' => 'queued', 'attempts' => 0, 'error' => null, 'next_attempt_at' => null]);
        DeliverWebhook::dispatch($delivery->id);

        AuditLog::record(
            actorId: $request->user()->id, actorName: $request->user()->name, action: 'webhook.redelivered',
            subjectType: Webhook::class, subjectId: $delivery->webhook_id, changes: ['event' => $delivery->event], ipAddress: $request->ip(),
        );

        return response()->json(['data' => ['id' => $delivery->id, 'status' => 'queued']]);
    }

    private function urlError(string $problem)
    {
        return response()->json([
            'code' => 'validation_error', 'message' => 'The given data was invalid.', 'errors' => ['url' => [$problem]],
        ], 422);
    }

    /**
     * @param  bool  $revealSecret  Only true right after creation -- a signing
     *                              secret is shown once, the same way an API key normally is, rather
     *                              than being retrievable indefinitely afterward.
     */
    private function serialize(Webhook $webhook, bool $revealSecret = false): array
    {
        return [
            'id' => $webhook->id,
            'url' => $webhook->url,
            'event' => $webhook->event,
            'isActive' => $webhook->is_active,
            'secret' => $revealSecret ? $webhook->secret : null,
            'createdAt' => $webhook->created_at,
        ];
    }
}
