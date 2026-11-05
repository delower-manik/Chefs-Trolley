<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Services\Notify\FcmClient;
use App\Services\Notify\Notifier;
use App\Services\Notify\SmsGateway;
use Illuminate\Http\Request;

/** Delivery log for SMS / email / push, plus a test send and retry for failures. */
class NotificationDeliveryController extends Controller
{
    /** GET /notification-deliveries?status=&channel= — latest 100 for the active branch (and chain-wide rows). */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $rows = NotificationDelivery::query()
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->latest()->limit(100)->get();

        return response()->json([
            'data' => $rows->map(fn ($d) => $this->serialize($d)),
            'channels' => [
                'sms' => ['configured' => SmsGateway::isConfigured(), 'driver' => SmsGateway::driver()],
                'push' => ['configured' => FcmClient::isConfigured()],
                'email' => ['configured' => config('mail.default') !== 'log' && config('mail.default') !== 'array', 'driver' => config('mail.default')],
            ],
        ]);
    }

    /** POST /notification-deliveries/test {channel, recipient} — verify a gateway with your own credentials. */
    public function test(Request $request, Notifier $notifier)
    {
        $data = $request->validate([
            'channel' => ['required', 'in:sms,email,push'],
            'recipient' => ['required', 'string', 'max:500'],
        ]);
        if ($data['channel'] === 'email' && ! filter_var($data['recipient'], FILTER_VALIDATE_EMAIL)) {
            return response()->json(['code' => 'validation_error', 'message' => 'The given data was invalid.', 'errors' => ['recipient' => ['Enter a valid email address.']]], 422);
        }

        $delivery = $notifier->queue(
            $data['channel'], $data['recipient'], 'This is a test message from Chefs Trolley.', 'Chefs Trolley test', 'test',
            $request->attributes->get('branch_id'),
        );
        $this->recordAudit($request, 'notification.test_sent', $delivery, ['channel' => $data['channel']]);

        return response()->json(['data' => $this->serialize($delivery)], 202);
    }

    /** POST /notification-deliveries/{delivery}/retry */
    public function retry(Request $request, NotificationDelivery $delivery)
    {
        if ($delivery->status !== 'failed') {
            return response()->json(['code' => 'not_failed', 'message' => 'Only failed deliveries can be retried.'], 422);
        }
        $delivery->update(['status' => 'queued', 'attempts' => 0, 'error' => null]);
        SendNotificationDelivery::dispatch($delivery->id);

        return response()->json(['data' => $this->serialize($delivery->fresh())]);
    }

    private function serialize(NotificationDelivery $d): array
    {
        return [
            'id' => $d->id, 'channel' => $d->channel, 'recipient' => $this->mask($d->channel, $d->recipient), 'subject' => $d->subject,
            'purpose' => $d->purpose, 'status' => $d->status, 'provider' => $d->provider, 'attempts' => $d->attempts,
            'error' => $d->error, 'sentAt' => $d->sent_at?->toIso8601String(), 'createdAt' => $d->created_at?->toIso8601String(),
        ];
    }

    /** The log is visible to staff who manage notifications; don't print full phone numbers/emails/device tokens. */
    private function mask(string $channel, string $recipient): string
    {
        return match ($channel) {
            'sms' => strlen($recipient) > 6 ? substr($recipient, 0, 4).str_repeat('•', strlen($recipient) - 6).substr($recipient, -2) : '••••',
            'email' => preg_replace('/^(.).*(@.*)$/', '$1•••$2', $recipient) ?? '•••',
            default => substr($recipient, 0, 6).'…',
        };
    }
}
