<?php

namespace Tests\Feature\Notify;

use App\Services\Notify\SmsGateway;
use App\Services\Notify\SmsNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsGatewayTest extends TestCase
{
    /** Http::fake() appends stubs and the first match wins, so reset the factory before each re-fake. */
    private function fake(mixed $stubs = null): void
    {
        Http::swap(new Factory);
        $stubs === null ? Http::fake() : Http::fake($stubs);
    }

    private function driver(string $driver, array $cfg = []): void
    {
        config(['notify.sms.driver' => $driver, "notify.sms.{$driver}" => array_merge(config("notify.sms.{$driver}") ?? [], $cfg)]);
    }

    public function test_bd_numbers_are_normalised_or_rejected(): void
    {
        foreach (['01712345678', '+8801712345678', '8801712345678', '008801712345678', '1712345678', '017-1234 5678'] as $raw) {
            $this->assertSame('8801712345678', SmsNumber::bd($raw), $raw);
        }
        foreach (['', null, '0171234567', '01212345678', '12345', '+14155550100', 'abc'] as $bad) {
            $this->assertNull(SmsNumber::bd($bad), (string) $bad);
        }
    }

    public function test_no_driver_means_no_sms(): void
    {
        $this->fake();
        $r = (new SmsGateway)->send('01712345678', 'hi');

        $this->assertFalse($r->ok);
        $this->assertSame('sms_not_configured', $r->error);
        Http::assertNothingSent();
    }

    public function test_invalid_number_is_not_sent_and_not_retried(): void
    {
        $this->driver('bulksmsbd', ['api_key' => 'k', 'sender_id' => 's']);
        $this->fake();

        $r = (new SmsGateway)->send('12345', 'hi');

        $this->assertSame('invalid_number', $r->error);
        $this->assertFalse($r->retryable);
        Http::assertNothingSent();
    }

    public function test_bulksmsbd(): void
    {
        $this->driver('bulksmsbd', ['api_key' => 'KEY', 'sender_id' => 'SENDER']);
        $this->fake(['bulksmsbd.net/*' => Http::response(['response_code' => 202, 'message' => 'SMS Submitted'], 200)]);

        $this->assertTrue((new SmsGateway)->send('01712345678', 'Hello')->ok);
        Http::assertSent(fn ($r) => $r['api_key'] === 'KEY' && $r['number'] === '8801712345678' && $r['senderid'] === 'SENDER' && $r['message'] === 'Hello');

        $this->fake(['bulksmsbd.net/*' => Http::response(['response_code' => 1007, 'error_message' => 'Balance Insufficient'], 200)]);
        $r = (new SmsGateway)->send('01712345678', 'Hello');
        $this->assertFalse($r->ok);
        $this->assertFalse($r->retryable);
    }

    public function test_sslwireless(): void
    {
        $this->driver('sslwireless', ['api_token' => 'T', 'sid' => 'SID']);
        $this->fake(['*sslwireless.com/*' => Http::response(['status' => 'SUCCESS', 'status_code' => 200], 200)]);

        $r = (new SmsGateway)->send('01712345678', 'Hello');

        $this->assertTrue($r->ok);
        Http::assertSent(fn ($req) => $req['msisdn'] === '8801712345678' && $req['sid'] === 'SID' && $req['api_token'] === 'T' && ! empty($req['csms_id']));

        $this->fake(['*sslwireless.com/*' => Http::response(['status' => 'FAILED', 'error_message' => 'bad'], 200)]);
        $this->assertFalse((new SmsGateway)->send('01712345678', 'Hello')->ok);
    }

    public function test_greenweb(): void
    {
        $this->driver('greenweb', ['token' => 'TK']);
        $this->fake(['*greenweb.com.bd/*' => Http::response('Ok: Message sent', 200)]);
        $this->assertTrue((new SmsGateway)->send('01712345678', 'Hello')->ok);

        $this->fake(['*greenweb.com.bd/*' => Http::response('Error: Invalid token', 200)]);
        $this->assertFalse((new SmsGateway)->send('01712345678', 'Hello')->ok);
    }

    public function test_alphasms(): void
    {
        $this->driver('alphasms', ['api_key' => 'AK']);
        $this->fake(['*sms.net.bd/*' => Http::response(['error' => 0, 'msg' => 'Request successfully submitted', 'data' => ['request_id' => 77]], 200)]);

        $r = (new SmsGateway)->send('01712345678', 'Hello');

        $this->assertTrue($r->ok);
        $this->assertSame('77', $r->messageId);

        $this->fake(['*sms.net.bd/*' => Http::response(['error' => 403, 'msg' => 'Invalid API key'], 200)]);
        $this->assertFalse((new SmsGateway)->send('01712345678', 'Hello')->ok);
    }

    public function test_elitbuzz(): void
    {
        $this->driver('elitbuzz', ['api_key' => 'EK', 'sender_id' => 'S']);
        $this->fake(['*elitbuzz-bd.com/*' => Http::response('SMS SUBMITTED SUCCESSFULLY', 200)]);
        $this->assertTrue((new SmsGateway)->send('01712345678', 'Hello')->ok);

        $this->fake(['*elitbuzz-bd.com/*' => Http::response('Error: invalid senderid', 200)]);
        $this->assertFalse((new SmsGateway)->send('01712345678', 'Hello')->ok);
    }

    public function test_twilio_uses_e164_basic_auth_and_a_messaging_service_when_set(): void
    {
        $this->driver('twilio', ['sid' => 'ACxxx', 'token' => 'tok', 'from' => '+15005550006', 'messaging_service_sid' => null]);
        $this->fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        $r = (new SmsGateway)->send('01712345678', 'Hello');

        $this->assertTrue($r->ok);
        $this->assertSame('SM123', $r->messageId);
        Http::assertSent(fn ($req) => $req->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACxxx/Messages.json'
            && $req['To'] === '+8801712345678' && $req['From'] === '+15005550006' && $req->hasHeader('Authorization'));

        $this->driver('twilio', ['messaging_service_sid' => 'MG1']);
        (new SmsGateway)->send('+14155550100', 'Hello');
        Http::assertSent(fn ($req) => ($req['MessagingServiceSid'] ?? null) === 'MG1' && $req['To'] === '+14155550100' && ! isset($req['From']));
    }

    public function test_server_errors_and_timeouts_are_retryable_but_client_errors_are_not(): void
    {
        $this->driver('greenweb', ['token' => 'TK']);

        $this->fake(['*' => Http::response('oops', 503)]);
        $this->assertTrue((new SmsGateway)->send('01712345678', 'x')->retryable);

        $this->fake(['*' => Http::response('slow down', 429)]);
        $this->assertTrue((new SmsGateway)->send('01712345678', 'x')->retryable);

        $this->fake(['*' => Http::response('nope', 401)]);
        $this->assertFalse((new SmsGateway)->send('01712345678', 'x')->retryable);

        $this->fake(fn () => throw new ConnectionException('timed out'));
        $r = (new SmsGateway)->send('01712345678', 'x');
        $this->assertTrue($r->retryable);
        $this->assertStringContainsString('connection_error', $r->error);
    }

    public function test_an_unknown_driver_fails_cleanly(): void
    {
        config(['notify.sms.driver' => 'mystery']);
        $r = (new SmsGateway)->send('01712345678', 'x');
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('unknown_sms_driver', $r->error);
    }
}
