<?php

namespace Tests\Feature\Tables;

use App\Models\DiningTable;
use App\Models\Reservation;
use Tests\TestCase;

class ReservationConflictTest extends TestCase
{
    public function test_overlapping_reservation_on_the_same_table_is_rejected(): void
    {
        $user = $this->actingAsUserWithPermissions(['reservations.manage']);
        $table = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T-1', 'capacity' => 4]);

        Reservation::create([
            'branch_id' => $user->active_branch_id,
            'dining_table_id' => $table->id,
            'customer_name' => 'Existing Guest',
            'customer_phone' => '01700000000',
            'party_size' => 2,
            'reserved_for' => now()->addHours(2),
            'duration_minutes' => 90,
            'status' => 'confirmed',
        ]);

        // Overlaps: starts 30 minutes into the existing 90-minute booking.
        $response = $this->postJson('/api/v1/reservations', [
            'branch_id' => $user->active_branch_id,
            'dining_table_id' => $table->id,
            'customer_name' => 'New Guest',
            'customer_phone' => '01800000000',
            'party_size' => 2,
            'reserved_for' => now()->addHours(2)->addMinutes(30)->toISOString(),
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'table_double_booked');
    }

    public function test_back_to_back_reservations_that_do_not_overlap_are_allowed(): void
    {
        $user = $this->actingAsUserWithPermissions(['reservations.manage']);
        $table = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T-2', 'capacity' => 4]);

        Reservation::create([
            'branch_id' => $user->active_branch_id,
            'dining_table_id' => $table->id,
            'customer_name' => 'Existing Guest',
            'customer_phone' => '01700000000',
            'party_size' => 2,
            'reserved_for' => now()->addHours(2),
            'duration_minutes' => 60,
            'status' => 'confirmed',
        ]);

        // Starts exactly when the first one ends -- no overlap.
        $response = $this->postJson('/api/v1/reservations', [
            'branch_id' => $user->active_branch_id,
            'dining_table_id' => $table->id,
            'customer_name' => 'New Guest',
            'customer_phone' => '01800000000',
            'party_size' => 2,
            'reserved_for' => now()->addHours(3)->toISOString(),
        ]);

        $response->assertStatus(201);
    }

    public function test_cancelled_reservations_do_not_block_new_bookings(): void
    {
        $user = $this->actingAsUserWithPermissions(['reservations.manage']);
        $table = DiningTable::create(['branch_id' => $user->active_branch_id, 'label' => 'T-3', 'capacity' => 4]);

        Reservation::create([
            'branch_id' => $user->active_branch_id,
            'dining_table_id' => $table->id,
            'customer_name' => 'Cancelled Guest',
            'customer_phone' => '01700000000',
            'party_size' => 2,
            'reserved_for' => now()->addHours(2),
            'duration_minutes' => 90,
            'status' => 'cancelled',
        ]);

        $response = $this->postJson('/api/v1/reservations', [
            'branch_id' => $user->active_branch_id,
            'dining_table_id' => $table->id,
            'customer_name' => 'New Guest',
            'customer_phone' => '01800000000',
            'party_size' => 2,
            'reserved_for' => now()->addHours(2)->addMinutes(15)->toISOString(),
        ]);

        $response->assertStatus(201);
    }

    public function test_only_pending_or_confirmed_reservations_can_be_cancelled(): void
    {
        $user = $this->actingAsUserWithPermissions(['reservations.manage']);
        $reservation = Reservation::create([
            'branch_id' => $user->active_branch_id,
            'customer_name' => 'Guest',
            'customer_phone' => '01700000000',
            'party_size' => 2,
            'reserved_for' => now()->addHour(),
            'status' => 'completed',
        ]);

        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_reservation_state');
    }
}
