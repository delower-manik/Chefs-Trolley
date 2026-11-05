<?php

namespace Tests\Feature\Realtime;

use App\Services\Realtime\NullPublisher;
use App\Services\Realtime\Publisher;
use App\Services\Realtime\PusherPublisher;
use App\Services\Realtime\Realtime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use TakesPayments;

    private function usePusher(array $extra = []): void
    {
        config(['realtime.driver' => 'pusher', 'realtime.pusher' => array_merge([
            'app_id' => '123', 'key' => 'appkey', 'secret' => 'appsecret', 'cluster' => 'ap2', 'host' => null, 'port' => null, 'scheme' => 'https',
        ], $extra)]);
        app()->forgetInstance(Publisher::class);
    }

    public function test_default_driver_is_a_silent_no_op(): void
    {
        $this->assertInstanceOf(NullPublisher::class, Realtime::publisher());
        Http::fake();
        [, $order] = $this->cashierWithOrder();
        Http::assertNothingSent();
    }

    public function test_pusher_request_signing_matches_the_documented_algorithm(): void
    {
        $p = new PusherPublisher(['app_id' => '3', 'key' => '278d425bdf160c739803', 'secret' => '7ad3773142a6692b25b8', 'cluster' => 'mt1', 'host' => null, 'port' => null]);
        $body = '{"name":"foo","channels":["project-3"],"data":"{\"some\":\"data\"}"}';

        $query = $p->signedQuery('POST', '/apps/3/events', $body, 1353088179);

        // Pusher's published REST example: https://pusher.com/docs/channels/library_auth_reference/rest-api/
        $this->assertStringContainsString('auth_key=278d425bdf160c739803&auth_timestamp=1353088179&auth_version=1.0&body_md5=ec365a775a4cd0599faeb73354201b6f', $query);
        $this->assertStringContainsString('auth_signature='.hash_hmac('sha256', "POST\n/apps/3/events\nauth_key=278d425bdf160c739803&auth_timestamp=1353088179&auth_version=1.0&body_md5=ec365a775a4cd0599faeb73354201b6f", '7ad3773142a6692b25b8'), $query);
    }

    public function test_creating_an_order_publishes_to_the_branch_channel_only_after_commit(): void
    {
        $this->usePusher();
        Http::fake(['*' => Http::response('{}', 200)]);

        [$user, $order] = $this->cashierWithOrder();

        Http::assertSent(function ($request) use ($user) {
            $body = json_decode($request->body(), true);

            return str_starts_with($request->url(), 'https://api-ap2.pusher.com/apps/123/events?')
                && str_contains($request->url(), 'auth_signature=')
                && $body['name'] === 'order.changed'
                && $body['channels'] === ['private-branch.'.$user->active_branch_id];
        });
    }

    public function test_a_failing_provider_never_breaks_the_sale(): void
    {
        $this->usePusher();
        Http::fake(['*' => Http::response('nope', 500)]);

        [, $order] = $this->cashierWithOrder();

        $this->assertNotNull($order->fresh());
    }

    public function test_an_unreachable_provider_never_breaks_the_sale(): void
    {
        $this->usePusher();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        [, $order] = $this->cashierWithOrder();

        $this->assertNotNull($order->fresh());
    }

    public function test_reverb_uses_the_pusher_protocol_with_a_custom_host(): void
    {
        config(['realtime.driver' => 'reverb']);
        $this->usePusher(['host' => 'ws.example.com', 'port' => 8080, 'scheme' => 'http']);
        config(['realtime.driver' => 'reverb']);
        app()->forgetInstance(Publisher::class);
        Http::fake(['*' => Http::response('{}', 200)]);

        $this->cashierWithOrder();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://ws.example.com:8080/apps/123/events?'));
    }

    public function test_client_config_exposes_no_secrets(): void
    {
        $this->usePusher();
        $user = $this->actingAsUserWithPermissions(['orders.view']);

        $res = $this->getJson('/api/v1/realtime/config')->assertOk()
            ->assertJsonPath('data.driver', 'pusher')->assertJsonPath('data.key', 'appkey')->assertJsonPath('data.branchId', $user->active_branch_id);

        $this->assertStringNotContainsString('appsecret', $res->getContent());
    }

    public function test_private_channel_auth_only_signs_the_users_own_branch(): void
    {
        $this->usePusher();
        $user = $this->actingAsUserWithPermissions(['orders.view']);
        $own = 'private-branch.'.$user->active_branch_id;

        $this->postJson('/api/v1/realtime/auth', ['socket_id' => '1234.5678', 'channel_name' => $own])
            ->assertOk()->assertJsonPath('auth', 'appkey:'.hash_hmac('sha256', "1234.5678:{$own}", 'appsecret'));

        $this->postJson('/api/v1/realtime/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-branch.'.$this->createBranch()->id])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden_channel');
        $this->postJson('/api/v1/realtime/auth', ['socket_id' => '1234.5678', 'channel_name' => 'presence-all'])->assertStatus(403);
    }

    public function test_realtime_endpoints_require_sign_in(): void
    {
        $this->getJson('/api/v1/realtime/config')->assertStatus(401);
        $this->postJson('/api/v1/realtime/auth', [])->assertStatus(401);
    }

    public function test_ably_token_request_is_subscribe_only_for_the_active_branch_and_signed(): void
    {
        config(['realtime.driver' => 'ably', 'realtime.ably' => ['key' => 'keyName:keySecret', 'rest_url' => 'https://rest.ably.io']]);
        app()->forgetInstance(Publisher::class);
        $user = $this->actingAsUserWithPermissions(['orders.view']);

        $res = $this->postJson('/api/v1/realtime/ably-token')->assertOk()->assertJsonPath('keyName', 'keyName');
        $channel = 'branch:'.$user->active_branch_id;
        $this->assertSame([$channel => ['subscribe']], json_decode($res->json('capability'), true));

        $t = $res->json();
        $sign = implode("\n", [$t['keyName'], $t['ttl'], $t['capability'], $t['clientId'], $t['timestamp'], $t['nonce']])."\n";
        $this->assertSame(base64_encode(hash_hmac('sha256', $sign, 'keySecret', true)), $t['mac']);
        $this->assertStringNotContainsString('keySecret', $res->getContent());
    }

    public function test_ably_publish_uses_basic_auth_and_the_branch_channel(): void
    {
        config(['realtime.driver' => 'ably', 'realtime.ably' => ['key' => 'keyName:keySecret', 'rest_url' => 'https://rest.ably.io']]);
        app()->forgetInstance(Publisher::class);
        Http::fake(['*' => Http::response('{}', 201)]);

        [$user] = $this->cashierWithOrder();

        Http::assertSent(fn ($r) => $r->url() === 'https://rest.ably.io/channels/'.rawurlencode('branch:'.$user->active_branch_id).'/messages'
            && $r->hasHeader('Authorization') && json_decode($r->body(), true)['name'] === 'order.changed');
    }

    public function test_ably_token_is_refused_when_ably_is_not_the_provider(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);
        $this->postJson('/api/v1/realtime/ably-token')->assertStatus(422)->assertJsonPath('code', 'realtime_unavailable');
    }

    public function test_payment_state_changes_are_pushed_too(): void
    {
        [, $order] = $this->cashierWithOrder();
        $this->usePusher();
        Http::fake(['*' => Http::response('{}', 200)]);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'card'])->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->body(), 'order.changed') && str_contains($r->body(), 'paymentState'));
    }
}
