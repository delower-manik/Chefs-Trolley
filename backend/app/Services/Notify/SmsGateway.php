<?php

namespace App\Services\Notify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SMS drivers over plain HTTP (no SDKs). Bangladeshi gateways get 8801XXXXXXXXX; Twilio gets +8801XXXXXXXXX.
 *
 * IMPORTANT: request shapes follow each provider's public documentation as understood when written but have
 * NOT been exercised against live accounts. Verify each with your own credentials (the Notifications page
 * can send a test) before relying on it.
 */
class SmsGateway
{
    public static function driver(): string
    {
        return (string) config('notify.sms.driver', 'none');
    }

    public static function isConfigured(): bool
    {
        return self::driver() !== 'none';
    }

    public function send(string $to, string $message): SmsResult
    {
        $driver = self::driver();
        if ($driver === 'none') {
            return SmsResult::failed('sms_not_configured');
        }

        $number = SmsNumber::bd($to);
        if (! $number && $driver !== 'twilio') {
            return SmsResult::failed('invalid_number');
        }

        try {
            return match ($driver) {
                'twilio' => $this->twilio($number ?? $to, $message),
                'bulksmsbd' => $this->bulksmsbd($number, $message),
                'sslwireless' => $this->sslwireless($number, $message),
                'greenweb' => $this->greenweb($number, $message),
                'alphasms' => $this->alphasms($number, $message),
                'elitbuzz' => $this->elitbuzz($number, $message),
                default => SmsResult::failed("unknown_sms_driver:{$driver}"),
            };
        } catch (ConnectionException $e) {
            return SmsResult::failed('connection_error: '.$e->getMessage(), true);
        }
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('notify.sms.timeout', 10));
    }

    private function fail(Response $r, string $driver): SmsResult
    {
        return SmsResult::failed("{$driver} HTTP {$r->status()}: ".substr($r->body(), 0, 200), $r->serverError() || $r->status() === 429);
    }

    private function twilio(string $to, string $message): SmsResult
    {
        $c = config('notify.sms.twilio');
        $to = str_starts_with($to, '+') ? $to : '+'.ltrim($to, '+');
        $body = ['To' => $to, 'Body' => $message];
        if ($c['messaging_service_sid']) {
            $body['MessagingServiceSid'] = $c['messaging_service_sid'];
        } else {
            $body['From'] = $c['from'];
        }

        $r = $this->http()->withBasicAuth((string) $c['sid'], (string) $c['token'])->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['sid']}/Messages.json", $body);

        return $r->successful() ? SmsResult::sent($r->json('sid')) : $this->fail($r, 'twilio');
    }

    private function bulksmsbd(string $to, string $message): SmsResult
    {
        $c = config('notify.sms.bulksmsbd');
        $r = $this->http()->asForm()->post($c['url'], [
            'api_key' => $c['api_key'], 'type' => 'text', 'number' => $to, 'senderid' => $c['sender_id'], 'message' => $message,
        ]);
        if (! $r->successful()) {
            return $this->fail($r, 'bulksmsbd');
        }

        return (int) $r->json('response_code') === 202
            ? SmsResult::sent()
            : SmsResult::failed('bulksmsbd: '.substr($r->body(), 0, 200));
    }

    private function sslwireless(string $to, string $message): SmsResult
    {
        $c = config('notify.sms.sslwireless');
        $csms = bin2hex(random_bytes(8));
        $r = $this->http()->asJson()->post($c['url'], [
            'api_token' => $c['api_token'], 'sid' => $c['sid'], 'msisdn' => $to, 'sms' => $message, 'csms_id' => $csms,
        ]);
        if (! $r->successful()) {
            return $this->fail($r, 'sslwireless');
        }

        return strtoupper((string) $r->json('status')) === 'SUCCESS'
            ? SmsResult::sent($csms)
            : SmsResult::failed('sslwireless: '.substr($r->body(), 0, 200));
    }

    private function greenweb(string $to, string $message): SmsResult
    {
        $c = config('notify.sms.greenweb');
        $r = $this->http()->asForm()->post($c['url'], ['token' => $c['token'], 'to' => $to, 'message' => $message]);
        if (! $r->successful()) {
            return $this->fail($r, 'greenweb');
        }

        return str_starts_with(trim($r->body()), 'Ok')
            ? SmsResult::sent()
            : SmsResult::failed('greenweb: '.substr($r->body(), 0, 200));
    }

    private function alphasms(string $to, string $message): SmsResult
    {
        $c = config('notify.sms.alphasms');
        $r = $this->http()->asForm()->post($c['url'], ['api_key' => $c['api_key'], 'msg' => $message, 'to' => $to]);
        if (! $r->successful()) {
            return $this->fail($r, 'alphasms');
        }

        return (int) $r->json('error', 1) === 0
            ? SmsResult::sent((string) $r->json('data.request_id'))
            : SmsResult::failed('alphasms: '.substr($r->body(), 0, 200));
    }

    private function elitbuzz(string $to, string $message): SmsResult
    {
        $c = config('notify.sms.elitbuzz');
        $r = $this->http()->asForm()->post($c['url'], [
            'api_key' => $c['api_key'], 'type' => 'text', 'contacts' => $to, 'senderid' => $c['sender_id'], 'msg' => $message,
        ]);
        if (! $r->successful()) {
            return $this->fail($r, 'elitbuzz');
        }

        // This provider answers in plain text; treat any error-looking reply as a failure.
        return preg_match('/error|invalid|fail|not enough|insufficient|denied/', strtolower($r->body()))
            ? SmsResult::failed('elitbuzz: '.substr($r->body(), 0, 200))
            : SmsResult::sent();
    }
}
