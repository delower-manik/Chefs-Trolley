<?php

namespace Tests\Feature\Notifications;

use App\Models\Customer;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    public function test_a_notification_is_created_as_a_draft_and_can_be_marked_sent_once(): void
    {
        $this->actingAsUserWithPermissions(['notifications.manage']);

        $create = $this->postJson('/api/v1/notifications', [
            'title' => 'Weekend Offer', 'body' => '20% off all biryani this weekend!', 'audience' => 'all_customers',
        ]);
        $create->assertStatus(201)->assertJsonPath('data.status', 'draft');

        $id = $create->json('data.id');

        // Sending needs someone reachable: with no customers it is refused instead of marked "sent".
        $this->postJson("/api/v1/notifications/{$id}/send", ['channels' => ['sms']])->assertStatus(422)->assertJsonPath('code', 'no_recipients');

        Customer::create(['name' => 'Ayesha', 'phone' => '01712345678']);
        $this->postJson("/api/v1/notifications/{$id}/send", ['channels' => ['sms']])->assertStatus(200)->assertJsonPath('data.status', 'sent');

        // Sending an already-sent notification again is rejected.
        $this->postJson("/api/v1/notifications/{$id}/send", ['channels' => ['sms']])->assertStatus(422)->assertJsonPath('code', 'already_sent');
    }

    public function test_a_notification_targeting_a_specific_customer_requires_one(): void
    {
        $this->actingAsUserWithPermissions(['notifications.manage']);

        $this->postJson('/api/v1/notifications', [
            'title' => 'Just for you', 'body' => 'A special discount', 'audience' => 'specific_customer',
        ])->assertStatus(422);

        $customer = Customer::create(['name' => 'VIP', 'phone' => '01788'.rand(100000, 999999)]);

        $this->postJson('/api/v1/notifications', [
            'title' => 'Just for you', 'body' => 'A special discount',
            'audience' => 'specific_customer', 'customer_id' => $customer->id,
        ])->assertStatus(201);
    }
}
