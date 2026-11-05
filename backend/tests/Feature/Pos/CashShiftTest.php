<?php

namespace Tests\Feature\Pos;

use Tests\Concerns\TakesPayments;
use Tests\TestCase;

class CashShiftTest extends TestCase
{
    use TakesPayments;

    public function test_shift_lifecycle_expected_cash_and_variance(): void
    {
        [, $order] = $this->cashierWithOrder(['orders.refund']);
        $shift = $this->openShift(500);

        $this->postJson('/api/v1/cash/shifts/open', ['opening_float' => 100])->assertStatus(422)->assertJsonPath('code', 'shift_already_open');

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'tip' => 10, 'tendered' => 500])->assertStatus(201); // +210
        $this->postJson("/api/v1/cash/shifts/{$shift}/movements", ['type' => 'out', 'amount' => 50, 'reason' => 'Buy ice'])->assertStatus(201);
        $this->postJson("/api/v1/cash/shifts/{$shift}/movements", ['type' => 'in', 'amount' => 20, 'reason' => 'Float top-up'])->assertStatus(201);
        $this->postJson("/api/v1/orders/{$order->id}/refunds", ['amount' => 25, 'method' => 'cash', 'reason' => 'Cold'])->assertStatus(201);

        // 500 + 210 - 50 + 20 - 25 = 655
        $this->getJson("/api/v1/cash/shifts/{$shift}/report")->assertOk()->assertJsonPath('data.expectedNow', 655);

        $this->postJson("/api/v1/cash/shifts/{$shift}/close", ['counted_cash' => 650, 'notes' => 'Short 5'])
            ->assertOk()->assertJsonPath('data.expectedCash', 655)->assertJsonPath('data.variance', -5)
            ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.byMethod.0.tips', 10);

        $this->postJson("/api/v1/cash/shifts/{$shift}/close", ['counted_cash' => 650])->assertStatus(422)->assertJsonPath('code', 'shift_closed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cash.shift_closed', 'subject_id' => $shift]);
    }

    public function test_cannot_pay_out_more_than_the_drawer_holds(): void
    {
        $this->actingAsUserWithPermissions($this->posPerms);
        $shift = $this->openShift(100);

        $this->postJson("/api/v1/cash/shifts/{$shift}/movements", ['type' => 'out', 'amount' => 150, 'reason' => 'Supplier'])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_drawer_cash');
    }

    public function test_only_the_cashier_who_opened_the_shift_can_change_it(): void
    {
        $user = $this->actingAsUserWithPermissions($this->posPerms);
        $shift = $this->openShift(100);

        $this->actingAsUserWithPermissions($this->posPerms, $user->activeBranch);
        $this->postJson("/api/v1/cash/shifts/{$shift}/close", ['counted_cash' => 100])->assertStatus(403)->assertJsonPath('code', 'not_your_shift');
    }

    public function test_current_shift_and_drawer_pulse(): void
    {
        $this->actingAsUserWithPermissions($this->posPerms);
        $this->getJson('/api/v1/cash/shifts/current')->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/v1/cash/drawer/open')->assertStatus(422)->assertJsonPath('code', 'no_open_shift');

        $id = $this->openShift(100);
        $this->getJson('/api/v1/cash/shifts/current')->assertJsonPath('data.id', $id);
        $b64 = $this->postJson('/api/v1/cash/drawer/open')->assertOk()->json('data.escposBase64');
        $this->assertSame("\x1B@\x1Bp\x00\x19\xFA", base64_decode($b64));
    }

    public function test_cash_management_needs_the_permission(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);
        $this->postJson('/api/v1/cash/shifts/open', ['opening_float' => 1])->assertStatus(403);
    }
}
