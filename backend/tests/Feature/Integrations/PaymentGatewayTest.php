<?php

namespace Tests\Feature\Integrations;

use App\Models\PaymentGatewayConfig;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    public function test_the_seeded_catalog_is_available_and_disabled_by_default(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage']);
        PaymentGatewayConfig::firstOrCreate(['gateway_key' => 'bkash'], ['display_name' => 'bKash']);

        $response = $this->getJson('/api/v1/payment-gateways');

        $response->assertJsonFragment(['gatewayKey' => 'bkash', 'isEnabled' => false, 'isConfigured' => false]);
    }

    public function test_enabling_a_gateway_and_saving_credentials_never_exposes_them_back(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage']);
        $gateway = PaymentGatewayConfig::create(['gateway_key' => 'nagad', 'display_name' => 'Nagad']);

        $response = $this->putJson("/api/v1/payment-gateways/{$gateway->id}", [
            'is_enabled' => true,
            'config' => ['merchant_id' => 'secret-merchant-id', 'merchant_number' => '01711111111', 'merchant_private_key' => 'super-secret-key', 'pg_public_key' => 'pub'],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.isEnabled', true)->assertJsonPath('data.isConfigured', true);

        // The response never contains the actual credential values.
        $response->assertJsonMissing(['merchant_id' => 'secret-merchant-id']);
        $response->assertJsonMissing(['merchant_private_key' => 'super-secret-key']);

        // Credentials are encrypted at rest, not stored as plaintext JSON in the database.
        $raw = DB::table('payment_gateway_configs')->where('id', $gateway->id)->value('config');
        $this->assertStringNotContainsString('super-secret-key', $raw);
    }
}
