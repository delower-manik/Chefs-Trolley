<?php

namespace Tests\Feature\Marketing;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketing\SegmentQuery;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SegmentCampaignTest extends TestCase
{
    private function manager(): User
    {
        $user = $this->actingAsUserWithPermissions(['campaigns.manage', 'customers.view', 'customers.manage', 'notifications.manage']);
        config(['queue.default' => 'sync', 'notify.sms.driver' => 'none']);

        return $user;
    }

    /** A customer with N paid orders of $each; the most recent one $daysAgo days ago. */
    private function customer(string $name, string $phone, int $orders = 0, float $each = 100, int $daysAgo = 1, array $extra = []): Customer
    {
        $c = Customer::create(['name' => $name, 'phone' => $phone] + $extra);
        for ($i = 0; $i < $orders; $i++) {
            $o = new Order;
            $o->forceFill([
                'branch_id' => Branch::first()->id, 'customer_id' => $c->id, 'code' => 'T'.uniqid(), 'source' => 'pos', 'status' => 'completed',
                'payment_state' => 'paid', 'subtotal' => $each, 'grand_total' => $each,
            ])->save();
            Order::whereKey($o->id)->update(['created_at' => now()->subDays($daysAgo + $i)]);
        }

        return $c;
    }

    private function matching(array $rules, string $match = 'all'): int
    {
        return $this->postJson('/api/v1/customer-segments/preview', ['match' => $match, 'rules' => $rules])->assertOk()->json('matching');
    }

    public function test_rules_match_on_spend_visits_recency_and_contact_details(): void
    {
        $this->manager();
        $this->customer('Big', '01711000001', orders: 3, each: 500, daysAgo: 2);      // 1500 over 3 orders, last 2 days ago
        $this->customer('Lapsed', '01711000002', orders: 2, each: 200, daysAgo: 90);  // 400 over 2 orders, last 90 days ago
        $this->customer('Newbie', '01711000003');                                      // never ordered
        $this->customer('NoPhone', '', orders: 1, each: 50, daysAgo: 5, extra: ['email' => 'a@b.com']);

        $this->assertSame(1, $this->matching([['field' => 'total_spent', 'op' => 'gte', 'value' => 1000]]));
        $this->assertSame(2, $this->matching([['field' => 'order_count', 'op' => 'gte', 'value' => 2]]));
        $this->assertSame(500, (int) $this->customerAvg());
        // "Lapsed": last order 60+ days ago — customers who never ordered must not count as lapsed.
        $this->assertSame(1, $this->matching([['field' => 'days_since_last_order', 'op' => 'gte', 'value' => 60]]));
        $this->assertSame(2, $this->matching([['field' => 'days_since_last_order', 'op' => 'lte', 'value' => 30]]));
        $this->assertSame(1, $this->matching([['field' => 'has_ordered', 'op' => 'eq', 'value' => false]]));
        $this->assertSame(1, $this->matching([['field' => 'has_phone', 'op' => 'eq', 'value' => false]]));
        $this->assertSame(1, $this->matching([['field' => 'has_email', 'op' => 'eq', 'value' => true]]));

        // all vs any, and an empty list meaning everyone.
        $both = [['field' => 'total_spent', 'op' => 'gte', 'value' => 1000], ['field' => 'order_count', 'op' => 'gte', 'value' => 2]];
        $this->assertSame(1, $this->matching($both, 'all'));
        $this->assertSame(2, $this->matching($both, 'any'));
        $this->assertSame(4, $this->matching([]));
    }

    private function customerAvg(): float
    {
        $q = app(SegmentQuery::class)->query([['field' => 'avg_order_value', 'op' => 'gte', 'value' => 500]], 'all', null);
        $this->assertSame(1, $q->count());

        return 500;
    }

    public function test_unpaid_cancelled_and_deleted_orders_do_not_count(): void
    {
        $this->manager();
        $c = $this->customer('Mixed', '01711000009', orders: 1, each: 300);
        foreach ([['payment_state' => 'unpaid'], ['payment_state' => 'refunded'], ['payment_state' => 'paid', 'deleted_at' => now()]] as $attrs) {
            $o = new Order;
            $o->forceFill(['branch_id' => Branch::first()->id, 'customer_id' => $c->id, 'code' => 'X'.uniqid(), 'source' => 'pos', 'status' => 'completed', 'grand_total' => 999] + $attrs)->save();
        }

        $this->assertSame(1, $this->matching([['field' => 'total_spent', 'op' => 'lte', 'value' => 300], ['field' => 'order_count', 'op' => 'eq', 'value' => 1]]));
    }

    public function test_rules_are_validated_and_segments_are_audited_and_permissioned(): void
    {
        $this->manager();

        $this->postJson('/api/v1/customer-segments/preview', ['match' => 'all', 'rules' => [['field' => 'password', 'op' => 'gte', 'value' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/customer-segments/preview', ['match' => 'all', 'rules' => [['field' => 'total_spent', 'op' => 'like', 'value' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/customer-segments/preview', ['match' => 'all', 'rules' => [['field' => 'total_spent', 'op' => 'gte', 'value' => 'abc']]])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_rule');
        $this->postJson('/api/v1/customer-segments/preview', ['match' => 'all', 'rules' => [['field' => 'has_phone', 'op' => 'gte', 'value' => 1]]])->assertStatus(422);

        $this->postJson('/api/v1/customer-segments', ['name' => 'VIP', 'match' => 'all', 'rules' => [['field' => 'total_spent', 'op' => 'gte', 'value' => 1000]]])->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', ['action' => 'segment.created']);

        $this->actingAsUserWithPermissions(['customers.view'], Branch::first());
        $this->getJson('/api/v1/customer-segments')->assertStatus(403);
        $this->getJson('/api/v1/campaigns')->assertStatus(403);
    }

    public function test_preview_reports_opted_out_and_reachable_counts(): void
    {
        $this->manager();
        $a = $this->customer('A', '01711000011', extra: ['email' => 'a@x.com']);
        $this->customer('B', '01711000012', extra: ['marketing_opt_out' => true, 'email' => 'b@x.com']);
        DeviceToken::create(['owner_type' => Customer::class, 'owner_id' => $a->id, 'token' => 'tok-a', 'platform' => 'android']);

        $p = $this->postJson('/api/v1/customer-segments/preview', ['match' => 'all', 'rules' => []])->assertOk()->json();
        $this->assertSame(2, $p['matching']);
        $this->assertSame(1, $p['optedOut']);
        $this->assertSame(['sms' => 1, 'email' => 1, 'push' => 1], $p['reachable']);
    }

    private function campaign(array $over = []): string
    {
        return $this->postJson('/api/v1/campaigns', $over + ['name' => 'Eid offer', 'title' => 'Eid Special', 'body' => 'Free dessert today', 'channels' => ['email', 'sms']])
            ->assertStatus(201)->json('data.id');
    }

    public function test_sending_queues_only_reachable_customers_never_opted_out_and_needs_the_confirmed_count(): void
    {
        $this->manager();
        $this->customer('Ayesha', '01711000021', extra: ['email' => 'ayesha@x.com']);
        $this->customer('Karim', '01711000022', extra: ['marketing_opt_out' => true, 'email' => 'karim@x.com']);
        $this->customer('Rina', '01711000023');
        $id = $this->campaign();

        $this->getJson("/api/v1/campaigns/{$id}/audience")->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.sms', 2)->assertJsonPath('data.email', 1);

        // Wrong confirmation: nothing is sent.
        $this->postJson("/api/v1/campaigns/{$id}/send", ['confirm_recipients' => 5])->assertStatus(409)->assertJsonPath('code', 'audience_changed');
        $this->assertSame(0, NotificationDelivery::count());

        $this->postJson("/api/v1/campaigns/{$id}/send", ['confirm_recipients' => 2])->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('queued.sms', 2)->assertJsonPath('queued.email', 1);

        $recipients = NotificationDelivery::pluck('recipient')->all();
        $this->assertNotContains('01711000022', $recipients);
        $this->assertNotContains('karim@x.com', $recipients);
        $email = NotificationDelivery::where('channel', 'email')->firstOrFail();
        $this->assertStringContainsString('/api/v1/marketing/unsubscribe/', $email->body);
        $this->assertSame('campaign', $email->purpose);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.sent']);

        // A campaign can only go out once.
        $this->postJson("/api/v1/campaigns/{$id}/send", ['confirm_recipients' => 2])->assertStatus(422)->assertJsonPath('code', 'already_sent');
        $this->assertSame(3, NotificationDelivery::count());
        $this->getJson("/api/v1/campaigns/{$id}")->assertOk()->assertJsonPath('data.audienceSize', 2);
    }

    public function test_an_empty_audience_and_oversized_audience_are_refused(): void
    {
        $this->manager();
        $id = $this->campaign();
        $this->postJson("/api/v1/campaigns/{$id}/send", ['confirm_recipients' => 0])->assertStatus(422)->assertJsonPath('code', 'no_recipients');

        $this->customer('One', '01711000031');
        $this->customer('Two', '01711000032');
        config(['notify.campaign_max_recipients' => 1]);
        $this->postJson("/api/v1/campaigns/{$id}/send", ['confirm_recipients' => 2])->assertStatus(422)->assertJsonPath('code', 'too_many_recipients');
        $this->assertSame('draft', Campaign::findOrFail($id)->status);
    }

    public function test_a_segment_limits_the_audience_and_must_belong_to_the_branch(): void
    {
        $this->manager();
        $this->customer('Big', '01711000041', orders: 2, each: 600);
        $this->customer('Small', '01711000042', orders: 1, each: 50);
        $segment = $this->postJson('/api/v1/customer-segments', ['name' => 'Big', 'match' => 'all', 'rules' => [['field' => 'total_spent', 'op' => 'gte', 'value' => 1000]]])->json('data.id');
        $id = $this->campaign(['segment_id' => $segment]);

        $this->getJson("/api/v1/campaigns/{$id}/audience")->assertJsonPath('data.total', 1);

        $other = CustomerSegment::create(['branch_id' => $this->createBranch()->id, 'name' => 'Elsewhere', 'match' => 'all', 'rules' => []]);
        $this->postJson('/api/v1/campaigns', ['name' => 'x', 'title' => 'x', 'body' => 'x', 'channels' => ['sms'], 'segment_id' => $other->id])
            ->assertStatus(422)->assertJsonPath('code', 'segment_not_found');
    }

    public function test_scheduled_campaigns_go_out_when_due_exactly_once_and_cancelled_ones_never(): void
    {
        $this->manager();
        $this->customer('Ayesha', '01711000051');

        $due = $this->campaign(['name' => 'due', 'scheduled_at' => now()->addMinute()->toIso8601String()]);
        $later = $this->campaign(['name' => 'later', 'scheduled_at' => now()->addDays(2)->toIso8601String()]);
        $cancelled = $this->campaign(['name' => 'cancelled', 'scheduled_at' => now()->addMinute()->toIso8601String()]);
        $this->postJson("/api/v1/campaigns/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('scheduled', Campaign::findOrFail($due)->status);

        $this->travel(2)->minutes();
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        $this->artisan('campaigns:dispatch')->assertSuccessful(); // a second run must not resend

        $this->assertSame('sent', Campaign::findOrFail($due)->status);
        $this->assertSame('scheduled', Campaign::findOrFail($later)->status);
        $this->assertSame('cancelled', Campaign::findOrFail($cancelled)->status);
        $this->assertSame(1, NotificationDelivery::where('reference_id', $due)->count());
        $this->assertSame(0, NotificationDelivery::where('reference_id', $cancelled)->count());
    }

    public function test_sent_campaigns_cannot_be_edited_or_deleted(): void
    {
        $this->manager();
        $this->customer('Ayesha', '01711000061');
        $id = $this->campaign();
        $this->postJson("/api/v1/campaigns/{$id}/send", ['confirm_recipients' => 1])->assertOk();

        $this->putJson("/api/v1/campaigns/{$id}", ['name' => 'n', 'title' => 't', 'body' => 'b', 'channels' => ['sms']])->assertStatus(422)->assertJsonPath('code', 'not_editable');
        $this->deleteJson("/api/v1/campaigns/{$id}")->assertStatus(422)->assertJsonPath('code', 'not_deletable');
    }

    public function test_unsubscribe_link_opts_the_customer_out_and_a_tampered_link_does_not(): void
    {
        $c = $this->customer('Ayesha', '01711000071', extra: ['email' => 'ayesha@x.com']);
        $url = URL::signedRoute('marketing.unsubscribe', ['customer' => $c->id]);

        $this->get($url.'x')->assertStatus(403);
        $this->assertFalse($c->fresh()->marketing_opt_out);

        $this->get($url)->assertOk()->assertSee('unsubscribed');
        $this->get($url)->assertOk(); // idempotent
        $this->assertTrue($c->fresh()->marketing_opt_out);
        $this->assertNotNull($c->fresh()->marketing_opt_out_at);
        $this->assertSame(1, AuditLog::where('action', 'customer.unsubscribed')->count());
    }

    public function test_opt_out_is_saved_through_the_customer_api_and_honoured_by_announcements(): void
    {
        $this->manager();
        $c = $this->customer('Ayesha', '01711000081');

        $this->putJson("/api/v1/customers/{$c->id}", ['name' => 'Ayesha', 'phone' => '01711000081', 'marketing_opt_out' => true])
            ->assertOk()->assertJsonPath('data.marketingOptOut', true);
        $this->assertNotNull($c->fresh()->marketing_opt_out_at);

        $n = $this->postJson('/api/v1/notifications', ['title' => 'Offer', 'body' => 'Hi', 'audience' => 'all_customers'])->json('data.id');
        $this->postJson("/api/v1/notifications/{$n}/send", ['channels' => ['sms']])->assertStatus(422)->assertJsonPath('code', 'no_recipients');

        $this->putJson("/api/v1/customers/{$c->id}", ['name' => 'Ayesha', 'phone' => '01711000081', 'marketing_opt_out' => false])->assertOk();
        $this->assertNull($c->fresh()->marketing_opt_out_at);
        $this->postJson("/api/v1/notifications/{$n}/send", ['channels' => ['sms']])->assertOk();
    }
}
