<?php

namespace Tests\Feature\Integrations;

use App\Models\WifiZone;
use Tests\TestCase;

class WifiVoucherTest extends TestCase
{
    public function test_vouchers_can_be_generated_in_a_batch_with_unique_codes(): void
    {
        $user = $this->actingAsUserWithPermissions(['integrations.manage']);
        $zone = WifiZone::create(['branch_id' => $user->activeBranch->id, 'name' => 'Dining Area', 'access_type' => 'voucher']);

        $response = $this->postJson("/api/v1/wifi-zones/{$zone->id}/vouchers/generate", [
            'duration_minutes' => 60, 'count' => 5,
        ]);

        $response->assertStatus(201)->assertJsonCount(5, 'data');
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertEquals(5, $codes->unique()->count());
    }

    public function test_a_voucher_can_only_be_redeemed_once(): void
    {
        $user = $this->actingAsUserWithPermissions(['integrations.manage']);
        $zone = WifiZone::create(['branch_id' => $user->activeBranch->id, 'name' => 'Rooftop', 'access_type' => 'voucher']);
        $voucherId = $this->postJson("/api/v1/wifi-zones/{$zone->id}/vouchers/generate", ['duration_minutes' => 30])
            ->json('data.0.id');

        $this->postJson("/api/v1/wifi/vouchers/{$voucherId}/redeem")
            ->assertStatus(200)->assertJsonPath('data.isUsed', true);

        $this->postJson("/api/v1/wifi/vouchers/{$voucherId}/redeem")
            ->assertStatus(422)->assertJsonPath('code', 'voucher_already_used');
    }

    public function test_two_zones_in_the_same_branch_cannot_share_a_name(): void
    {
        $this->actingAsUserWithPermissions(['integrations.manage']);

        $this->postJson('/api/v1/wifi-zones', ['name' => 'Main Hall'])->assertStatus(201);
        $this->postJson('/api/v1/wifi-zones', ['name' => 'Main Hall'])->assertStatus(422);
    }
}
