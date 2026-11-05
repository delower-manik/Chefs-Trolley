<?php

namespace Tests\Feature\Notify;

use App\Jobs\SendNotificationDelivery;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use TakesPayments;

    private function smsOn(): void
    {
        config(['notify.sms.driver' => 'greenweb', 'notify.sms.greenweb.token' => 'TK']);
    }

    private function fcmOn(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config(['notify.fcm.project_id' => 'proj-1', 'notify.fcm.credentials' => json_encode([
            'client_email' => 'svc@proj-1.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token',
        ])]);
        Cache::forget('fcm.access_token');
    }

    private function announcement(User $user, array $attrs = []): Notification
    {
        return Notification::create(array_merge([
            'branch_id' => $user->active_branch_id, 'title' => 'Happy hour', 'body' => '20% off drinks', 'audience' => 'all_customers',
            'status' => 'draft', 'created_by' => $user->id,
        ], $attrs));
    }

    // ---- the delivery job ----

    public function test_sms_delivery_is_sent_and_logged(): void
    {
        $this->smsOn();
        Http::fake(['*greenweb.com.bd/*' => Http::response('Ok: sent', 200)]);
        $d = NotificationDelivery::create(['channel' => 'sms', 'recipient' => '01712345678', 'body' => 'Hi']);

        SendNotificationDelivery::dispatchSync($d->id);

        $d->refresh();
        $this->assertSame('sent', $d->status);
        $this->assertSame('greenweb', $d->provider);
        $this->assertSame(1, $d->attempts);
        $this->assertNotNull($d->sent_at);
    }

    public function test_a_sent_delivery_is_never_sent_twice(): void
    {
        $this->smsOn();
        Http::fake(['*' => Http::response('Ok', 200)]);
        $d = NotificationDelivery::create(['channel' => 'sms', 'recipient' => '01712345678', 'body' => 'Hi']);

        SendNotificationDelivery::dispatchSync($d->id);
        SendNotificationDelivery::dispatchSync($d->id);

        Http::assertSentCount(1);
    }

    public function test_a_permanent_failure_is_marked_failed_with_the_reason(): void
    {
        $this->smsOn();
        Http::fake(['*' => Http::response('Error: Invalid token', 200)]);
        $d = NotificationDelivery::create(['channel' => 'sms', 'recipient' => '01712345678', 'body' => 'Hi']);

        SendNotificationDelivery::dispatchSync($d->id);

        $this->assertSame('failed', $d->fresh()->status);
        $this->assertStringContainsString('greenweb', $d->fresh()->error);
    }

    public function test_a_temporary_failure_stays_queued_for_a_retry(): void
    {
        $this->smsOn();
        Http::fake(['*' => Http::response('busy', 503)]);
        $d = NotificationDelivery::create(['channel' => 'sms', 'recipient' => '01712345678', 'body' => 'Hi']);

        SendNotificationDelivery::dispatchSync($d->id);

        $this->assertSame('queued', $d->fresh()->status);
        $this->assertStringContainsString('503', $d->fresh()->error);
    }

    public function test_email_delivery_uses_laravel_mail(): void
    {
        Mail::fake();
        $d = NotificationDelivery::create(['channel' => 'email', 'recipient' => 'guest@example.com', 'subject' => 'Hello', 'body' => 'Body']);

        SendNotificationDelivery::dispatchSync($d->id);

        $this->assertSame('sent', $d->fresh()->status);
    }

    public function test_push_delivery_exchanges_a_jwt_then_sends_and_drops_dead_tokens(): void
    {
        $this->fcmOn();
        $customer = Customer::create(['name' => 'Ayesha', 'phone' => '01712345678']);
        DeviceToken::create(['owner_type' => Customer::class, 'owner_id' => $customer->id, 'token' => 'tok-live', 'platform' => 'android']);
        DeviceToken::create(['owner_type' => Customer::class, 'owner_id' => $customer->id, 'token' => 'tok-dead', 'platform' => 'android']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'projects/proj-1/messages/1'], 200)
                ->push(['error' => ['status' => 'UNREGISTERED', 'message' => 'gone']], 404),
        ]);
        $live = NotificationDelivery::create(['channel' => 'push', 'recipient' => 'tok-live', 'subject' => 'T', 'body' => 'B']);
        $dead = NotificationDelivery::create(['channel' => 'push', 'recipient' => 'tok-dead', 'subject' => 'T', 'body' => 'B']);

        SendNotificationDelivery::dispatchSync($live->id);
        SendNotificationDelivery::dispatchSync($dead->id);

        $this->assertSame('sent', $live->fresh()->status);
        $this->assertSame('failed', $dead->fresh()->status);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'tok-dead']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'tok-live']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com') && str_contains((string) $r['assertion'], '.'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/projects/proj-1/messages:send') && $r['message']['token'] === 'tok-live'
            && $r->hasHeader('Authorization', 'Bearer ya29.x'));
        Http::assertSentCount(3); // one token exchange (cached), two sends
    }

    public function test_push_without_credentials_fails_without_calling_out(): void
    {
        Http::fake();
        $d = NotificationDelivery::create(['channel' => 'push', 'recipient' => 'tok', 'body' => 'B']);

        SendNotificationDelivery::dispatchSync($d->id);

        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame('push_not_configured', $d->fresh()->error);
        Http::assertNothingSent();
    }

    // ---- order status messages ----

    public function test_order_messages_are_off_by_default(): void
    {
        Http::fake();
        $user = $this->actingAsUserWithPermissions($this->posPerms);
        $customer = Customer::create(['name' => 'Ayesha', 'phone' => '01712345678']);
        $dish = $this->makeFoodItem($user->active_branch_id, 100);

        $this->postJson('/api/v1/orders', ['source' => 'delivery', 'customer_id' => $customer->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]]);

        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_online_order_status_changes_text_the_customer(): void
    {
        $this->smsOn();
        config(['notify.order_sms' => true]);
        Http::fake(['*' => Http::response('Ok', 200)]);
        $user = $this->actingAsUserWithPermissions($this->posPerms);
        $customer = Customer::create(['name' => 'Ayesha', 'phone' => '01712345678']);
        $dish = $this->makeFoodItem($user->active_branch_id, 100);

        $id = $this->postJson('/api/v1/orders', ['source' => 'delivery', 'customer_id' => $customer->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])
            ->assertStatus(201)->json('data.id');
        $order = Order::find($id);
        $order->update(['status' => 'delivered']);

        $bodies = NotificationDelivery::orderBy('created_at')->pluck('body')->all();
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString("received order {$order->code}", $bodies[0]);
        $this->assertStringContainsString('delivered', $bodies[1]);
        $this->assertSame(['sent', 'sent'], NotificationDelivery::pluck('status')->all());
    }

    public function test_walk_in_orders_never_message_anyone(): void
    {
        $this->smsOn();
        config(['notify.order_sms' => true]);
        Http::fake();
        $user = $this->actingAsUserWithPermissions($this->posPerms);
        $customer = Customer::create(['name' => 'Ayesha', 'phone' => '01712345678']);
        $dish = $this->makeFoodItem($user->active_branch_id, 100);

        $this->postJson('/api/v1/orders', ['source' => 'pos', 'customer_id' => $customer->id, 'items' => [['food_item_id' => $dish->id, 'quantity' => 1]]])->assertStatus(201);

        $this->assertSame(0, NotificationDelivery::count());
        Http::assertNothingSent();
    }

    // ---- announcements ----

    public function test_announcement_defaults_to_push_and_queues_per_registered_device(): void
    {
        $this->fcmOn();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'a'], 200), 'fcm.googleapis.com/*' => Http::response(['name' => 'm'], 200)]);
        $user = $this->actingAsUserWithPermissions(['notifications.manage']);
        $a = Customer::create(['name' => 'A', 'phone' => '01711111111', 'branch_id' => $user->active_branch_id]);
        Customer::create(['name' => 'B', 'phone' => '01722222222', 'branch_id' => $user->active_branch_id]); // no device
        Customer::create(['name' => 'Other branch', 'phone' => '01733333333', 'branch_id' => $this->createBranch()->id]);
        DeviceToken::create(['owner_type' => Customer::class, 'owner_id' => $a->id, 'token' => 'tok-a', 'platform' => 'ios']);
        $n = $this->announcement($user);

        $this->postJson("/api/v1/notifications/{$n->id}/send")->assertOk()
            ->assertJsonPath('queued.push', 1)->assertJsonPath('queued.sms', 0)->assertJsonPath('data.status', 'sent');

        $this->assertSame(1, NotificationDelivery::where('channel', 'push')->count());
        $this->assertSame(0, NotificationDelivery::where('channel', 'sms')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.sent', 'subject_id' => $n->id]);
    }

    public function test_sms_must_be_chosen_explicitly_and_stays_within_the_branch(): void
    {
        $this->smsOn();
        Http::fake(['*' => Http::response('Ok', 200)]);
        $user = $this->actingAsUserWithPermissions(['notifications.manage']);
        Customer::create(['name' => 'A', 'phone' => '01711111111', 'branch_id' => $user->active_branch_id]);
        Customer::create(['name' => 'Chain', 'phone' => '01744444444']); // chain-wide
        Customer::create(['name' => 'Gone', 'phone' => '01755555555', 'branch_id' => $user->active_branch_id, 'is_active' => false]);
        Customer::create(['name' => 'Other', 'phone' => '01733333333', 'branch_id' => $this->createBranch()->id]);
        $n = $this->announcement($user);

        $this->postJson("/api/v1/notifications/{$n->id}/send", ['channels' => ['sms']])->assertOk()->assertJsonPath('queued.sms', 2);

        $this->assertEqualsCanonicalizing(['01711111111', '01744444444'], NotificationDelivery::pluck('recipient')->all());
    }

    public function test_announcement_to_a_specific_customer_and_channel_validation(): void
    {
        $this->smsOn();
        Http::fake(['*' => Http::response('Ok', 200)]);
        $user = $this->actingAsUserWithPermissions(['notifications.manage']);
        $c = Customer::create(['name' => 'A', 'phone' => '01711111111', 'email' => 'a@example.com', 'branch_id' => $user->active_branch_id]);
        Customer::create(['name' => 'B', 'phone' => '01722222222', 'branch_id' => $user->active_branch_id]);
        $n = $this->announcement($user, ['audience' => 'specific_customer', 'customer_id' => $c->id]);

        $this->postJson("/api/v1/notifications/{$n->id}/send", ['channels' => ['fax']])->assertStatus(422);
        $this->postJson("/api/v1/notifications/{$n->id}/send", ['channels' => ['sms', 'email']])->assertOk()
            ->assertJsonPath('queued.sms', 1)->assertJsonPath('queued.email', 1);
    }

    public function test_nobody_reachable_does_not_mark_the_announcement_sent(): void
    {
        $user = $this->actingAsUserWithPermissions(['notifications.manage']);
        Customer::create(['name' => 'A', 'phone' => '01711111111', 'branch_id' => $user->active_branch_id]);
        $n = $this->announcement($user);

        $this->postJson("/api/v1/notifications/{$n->id}/send")->assertStatus(422)->assertJsonPath('code', 'no_recipients');
        $this->assertSame('draft', $n->fresh()->status);
    }

    public function test_an_announcement_cannot_be_sent_twice(): void
    {
        $user = $this->actingAsUserWithPermissions(['notifications.manage']);
        $n = $this->announcement($user, ['status' => 'sent']);

        $this->postJson("/api/v1/notifications/{$n->id}/send")->assertStatus(422)->assertJsonPath('code', 'already_sent');
    }

    // ---- log, test-send, retry ----

    public function test_delivery_log_masks_recipients_and_reports_channel_setup(): void
    {
        $user = $this->actingAsUserWithPermissions(['notifications.manage']);
        NotificationDelivery::create(['branch_id' => $user->active_branch_id, 'channel' => 'sms', 'recipient' => '8801712345678', 'body' => 'x', 'status' => 'sent']);
        NotificationDelivery::create(['branch_id' => $user->active_branch_id, 'channel' => 'email', 'recipient' => 'ayesha@example.com', 'body' => 'x']);
        NotificationDelivery::create(['branch_id' => $this->createBranch()->id, 'channel' => 'sms', 'recipient' => '8801799999999', 'body' => 'x']);

        $res = $this->getJson('/api/v1/notification-deliveries')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('channels.sms.configured', false);

        $this->assertStringNotContainsString('01712345678', $res->getContent());
        $this->assertStringNotContainsString('ayesha@', $res->getContent());
        $this->assertContains('a•••@example.com', collect($res->json('data'))->pluck('recipient')->all());
        $this->getJson('/api/v1/notification-deliveries?status=sent')->assertJsonCount(1, 'data');
    }

    public function test_test_send_goes_through_the_real_pipeline_and_is_audited(): void
    {
        $this->smsOn();
        Http::fake(['*' => Http::response('Ok', 200)]);
        $this->actingAsUserWithPermissions(['notifications.manage']);

        $this->postJson('/api/v1/notification-deliveries/test', ['channel' => 'sms', 'recipient' => '01712345678'])->assertStatus(202);
        $this->postJson('/api/v1/notification-deliveries/test', ['channel' => 'email', 'recipient' => 'not-an-email'])->assertStatus(422);

        $this->assertSame('sent', NotificationDelivery::first()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notification.test_sent']);
    }

    public function test_failed_deliveries_can_be_retried_but_sent_ones_cannot(): void
    {
        $this->smsOn();
        Http::fake(['*' => Http::response('Ok', 200)]);
        $user = $this->actingAsUserWithPermissions(['notifications.manage']);
        $failed = NotificationDelivery::create(['branch_id' => $user->active_branch_id, 'channel' => 'sms', 'recipient' => '01712345678', 'body' => 'x', 'status' => 'failed', 'error' => 'boom']);
        $sent = NotificationDelivery::create(['branch_id' => $user->active_branch_id, 'channel' => 'sms', 'recipient' => '01712345678', 'body' => 'x', 'status' => 'sent']);

        $this->postJson("/api/v1/notification-deliveries/{$failed->id}/retry")->assertOk();
        $this->assertSame('sent', $failed->fresh()->status);
        $this->postJson("/api/v1/notification-deliveries/{$sent->id}/retry")->assertStatus(422)->assertJsonPath('code', 'not_failed');
    }

    public function test_the_log_needs_the_notifications_permission_and_respects_branches(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.view']);
        $this->getJson('/api/v1/notification-deliveries')->assertStatus(403);

        $other = NotificationDelivery::create(['branch_id' => $this->createBranch()->id, 'channel' => 'sms', 'recipient' => '01712345678', 'body' => 'x', 'status' => 'failed']);
        $this->actingAsUserWithPermissions(['notifications.manage'], $user->activeBranch);
        $this->postJson("/api/v1/notification-deliveries/{$other->id}/retry")->assertStatus(404);
    }

    // ---- device tokens ----

    public function test_device_tokens_register_move_and_unregister(): void
    {
        $a = $this->actingAsUserWithPermissions(['orders.view']);
        $this->postJson('/api/v1/device-tokens', ['token' => 'tok-1', 'platform' => 'android'])->assertStatus(201);
        $this->assertDatabaseHas('device_tokens', ['token' => 'tok-1', 'owner_id' => $a->id]);

        $b = $this->actingAsUserWithPermissions(['orders.view'], $a->activeBranch);
        $this->postJson('/api/v1/device-tokens', ['token' => 'tok-1', 'platform' => 'android'])->assertStatus(201);
        $this->assertSame(1, DeviceToken::count());
        $this->assertDatabaseHas('device_tokens', ['token' => 'tok-1', 'owner_id' => $b->id]);

        $this->actingAsUserWithPermissions(['orders.view'], $a->activeBranch);
        $this->deleteJson('/api/v1/device-tokens', ['token' => 'tok-1'])->assertStatus(204);
        $this->assertSame(1, DeviceToken::count(), "someone else's token is not removable");

        $this->postJson('/api/v1/device-tokens', ['token' => 'x', 'platform' => 'symbian'])->assertStatus(422);
    }
}
