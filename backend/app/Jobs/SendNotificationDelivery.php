<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Services\Notify\FcmClient;
use App\Services\Notify\SmsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNotificationDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public string $deliveryId) {}

    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(): void
    {
        $d = NotificationDelivery::find($this->deliveryId);
        if (! $d || $d->status === 'sent') {
            return; // gone, or an earlier attempt already got through (never send twice)
        }

        $d->increment('attempts');

        [$ok, $error, $retryable, $provider, $messageId] = match ($d->channel) {
            'sms' => $this->sms($d),
            'email' => $this->email($d),
            'push' => $this->push($d),
            default => [false, "unknown_channel:{$d->channel}", false, null, null],
        };

        if ($ok) {
            $d->update(['status' => 'sent', 'sent_at' => now(), 'provider' => $provider, 'provider_message_id' => $messageId, 'error' => null]);

            return;
        }

        $d->update(['error' => $error, 'provider' => $provider]);
        if ($retryable && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);

            return;
        }
        $d->update(['status' => 'failed']);
    }

    public function failed(\Throwable $e): void
    {
        NotificationDelivery::where('id', $this->deliveryId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500)]);
    }

    private function sms(NotificationDelivery $d): array
    {
        $r = app(SmsGateway::class)->send($d->recipient, $d->body);

        return [$r->ok, $r->error, $r->retryable, SmsGateway::driver(), $r->messageId];
    }

    private function email(NotificationDelivery $d): array
    {
        try {
            Mail::raw($d->body, fn ($m) => $m->to($d->recipient)->subject($d->subject ?: config('app.name')));

            return [true, null, false, config('mail.default'), null];
        } catch (\Throwable $e) {
            return [false, 'mail: '.substr($e->getMessage(), 0, 300), true, config('mail.default'), null];
        }
    }

    private function push(NotificationDelivery $d): array
    {
        $r = app(FcmClient::class)->send($d->recipient, $d->subject ?: config('app.name'), $d->body, ['purpose' => $d->purpose]);
        if ($r['unregistered']) {
            DeviceToken::where('token', $d->recipient)->delete(); // app uninstalled; stop sending to it
        }

        return [$r['ok'], $r['error'], $r['retryable'], 'fcm', null];
    }
}
