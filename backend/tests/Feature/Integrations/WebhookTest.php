<?php

namespace Tests\Feature\Integrations;

use Tests\TestCase;

class WebhookTest extends TestCase
{
    public function test_a_webhook_secret_is_only_revealed_at_creation(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage']);

        $create = $this->postJson('/api/v1/webhooks', [
            'url' => 'https://93.184.216.34/hooks/orders', 'event' => 'order.created',
        ]);
        $create->assertStatus(201);
        $this->assertNotEmpty($create->json('data.secret'));

        $webhookId = $create->json('data.id');
        $list = $this->getJson('/api/v1/webhooks');
        $this->assertNull(collect($list->json('data'))->firstWhere('id', $webhookId)['secret']);
    }

    public function test_only_known_events_are_accepted(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage']);

        $this->postJson('/api/v1/webhooks', ['url' => 'https://93.184.216.34/hook', 'event' => 'made_up_event'])
            ->assertStatus(422);
    }
}
