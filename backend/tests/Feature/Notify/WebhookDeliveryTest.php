<?php

namespace Tests\Feature\Notify;

use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\Webhooks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use TakesPayments;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notify.webhooks.allow_insecure' => true]); // tests use fake hosts; the SSRF rules are tested separately
    }

    private function subscribe(string $event = 'order.created', string $url = 'https://hooks.example.com/in'): Webhook
    {
        return Webhook::create(['url' => $url, 'event' => $event, 'secret' => 'whsec_test', 'is_active' => true, 'created_by' => null]);
    }

    public function test_signature_is_a_timestamped_hmac_of_the_exact_body(): void
    {
        $sig = Webhooks::signature('whsec_test', '{"a":1}', 1700000000);

        $this->assertSame('t=1700000000,v1='.hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_test'), $sig);
    }

    public function test_a_new_order_posts_a_signed_event_to_subscribers_only(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        $hook = $this->subscribe('order.created');
        $this->subscribe('order.cancelled', 'https://other.example.com/in');

        [, $order] = $this->cashierWithOrder();

        Http::assertSentCount(1);
        Http::assertSent(function ($r) use ($order) {
            $payload = json_decode($r->body(), true);
            [$t, $v1] = explode(',', $r->header('X-ChefsTrolley-Signature')[0]);
            $ts = (int) substr($t, 2);

            return $r->url() === 'https://hooks.example.com/in'
                && $payload['event'] === 'order.created' && $payload['data']['code'] === $order->code
                && $r->header('X-ChefsTrolley-Event')[0] === 'order.created'
                && $r->header('X-ChefsTrolley-Delivery')[0] === $payload['id']
                && substr($v1, 3) === hash_hmac('sha256', $ts.'.'.$r->body(), 'whsec_test');
        });
        $this->assertSame('sent', WebhookDelivery::where('webhook_id', $hook->id)->first()->status);
    }

    public function test_cancel_and_delivered_events_fire_once_each(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->subscribe('order.cancelled');
        $this->subscribe('order.delivered', 'https://hooks.example.com/delivered');
        [, $order] = $this->cashierWithOrder();

        $order->update(['status' => 'cancelled']);
        $order->update(['grand_total' => 1]); // an unrelated change must not re-fire
        $order->update(['status' => 'delivered']);

        $this->assertSame(['order.cancelled', 'order.delivered'], WebhookDelivery::orderBy('created_at')->pluck('event')->all());
    }

    public function test_review_and_customer_events(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->subscribe('customer.created');

        Customer::create(['name' => 'Ayesha', 'phone' => '01712345678']);

        $this->assertSame(1, WebhookDelivery::where('event', 'customer.created')->count());
    }

    public function test_inactive_subscriptions_are_skipped(): void
    {
        Http::fake();
        $hook = $this->subscribe();
        $hook->update(['is_active' => false]);

        $this->cashierWithOrder();

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_server_errors_are_scheduled_for_retry_with_backoff(): void
    {
        Http::fake(['*' => Http::response('down', 502)]);
        $this->subscribe();

        $this->cashierWithOrder();

        $d = WebhookDelivery::first();
        $this->assertSame('queued', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertSame(502, $d->response_status);
        $this->assertTrue($d->next_attempt_at->isFuture());
    }

    public function test_client_errors_fail_immediately_without_retrying(): void
    {
        Http::fake(['*' => Http::response('gone', 410)]);
        $this->subscribe();

        $this->cashierWithOrder();

        $d = WebhookDelivery::first();
        $this->assertSame('failed', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertNull($d->next_attempt_at);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
        $this->subscribe();

        $this->cashierWithOrder();

        Http::assertSentCount(1);
        $this->assertSame('failed', WebhookDelivery::first()->status);
    }

    public function test_a_receiver_exception_is_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $this->subscribe();

        $this->cashierWithOrder();

        $d = WebhookDelivery::first();
        $this->assertSame('queued', $d->status);
        $this->assertStringContainsString('connection', $d->error);
    }

    public function test_an_already_delivered_event_is_not_posted_again(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->subscribe();
        $this->cashierWithOrder();
        $d = WebhookDelivery::first();

        DeliverWebhook::dispatchSync($d->id);

        Http::assertSentCount(1);
    }

    public function test_urls_to_private_or_insecure_hosts_are_refused_by_default(): void
    {
        config(['notify.webhooks.allow_insecure' => false]);

        $this->assertNotNull(Webhooks::urlProblem('http://example.com/x'));
        $this->assertNotNull(Webhooks::urlProblem('https://127.0.0.1/x'));
        $this->assertNotNull(Webhooks::urlProblem('https://10.0.0.5/x'));
        $this->assertNotNull(Webhooks::urlProblem('https://192.168.1.10/x'));
        $this->assertNotNull(Webhooks::urlProblem('https://169.254.169.254/latest/meta-data'));
        $this->assertNotNull(Webhooks::urlProblem('ftp://8.8.8.8/x'));
        $this->assertNotNull(Webhooks::urlProblem('not a url'));
        $this->assertNull(Webhooks::urlProblem('https://8.8.8.8/hook'));
    }

    public function test_the_api_rejects_unsafe_urls_on_create_and_update(): void
    {
        config(['notify.webhooks.allow_insecure' => false]);
        $this->actingAsUserWithPermissions(['integrations.manage']);

        $this->postJson('/api/v1/webhooks', ['url' => 'https://169.254.169.254/x', 'event' => 'order.created'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_error')->assertJsonStructure(['errors' => ['url']]);

        $id = $this->postJson('/api/v1/webhooks', ['url' => 'https://8.8.8.8/x', 'event' => 'order.created'])->assertStatus(201)->json('data.id');
        $this->putJson("/api/v1/webhooks/{$id}", ['url' => 'http://8.8.8.8/x'])->assertStatus(422);
    }

    public function test_a_url_that_became_unsafe_is_not_called_at_delivery_time(): void
    {
        $this->subscribe('order.created', 'https://10.0.0.5/x');
        config(['notify.webhooks.allow_insecure' => false]);
        Http::fake();

        $this->cashierWithOrder();

        Http::assertNothingSent();
        $d = WebhookDelivery::first();
        $this->assertSame('failed', $d->status);
        $this->assertStringContainsString('public address', $d->error);
    }

    public function test_delivery_log_and_manual_redeliver(): void
    {
        Http::fake(['*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
        $hook = $this->subscribe();
        $this->cashierWithOrder(['integrations.manage']);
        $d = WebhookDelivery::first();
        $d->update(['status' => 'failed']);

        $this->getJson("/api/v1/webhooks/{$hook->id}/deliveries")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.eventId', $d->event_id)->assertJsonPath('data.0.status', 'failed');

        $this->postJson("/api/v1/webhook-deliveries/{$d->id}/redeliver")->assertOk();
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertSame($d->event_id, json_decode(WebhookDelivery::find($d->id)->payload, true)['id']);
        $this->postJson("/api/v1/webhook-deliveries/{$d->id}/redeliver")->assertStatus(422)->assertJsonPath('code', 'already_delivered');
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.redelivered']);
    }

    public function test_the_secret_is_never_listed_after_creation(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage']);
        $this->subscribe();

        $this->getJson('/api/v1/webhooks')->assertOk()->assertJsonPath('data.0.secret', null);
    }
}
