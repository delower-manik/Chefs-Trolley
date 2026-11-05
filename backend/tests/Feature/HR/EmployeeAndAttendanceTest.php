<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use Tests\TestCase;

class EmployeeAndAttendanceTest extends TestCase
{
    public function test_an_employee_can_be_created_and_two_cannot_share_a_phone(): void
    {
        $this->actingAsUserWithPermissions(['hr.manage']);
        $phone = '017'.rand(10000000, 99999999);

        $this->postJson('/api/v1/employees', [
            'name' => 'Abdul Karim', 'phone' => $phone, 'role_title' => 'Head Chef', 'monthly_salary' => 30000,
        ])->assertStatus(201)->assertJsonPath('data.roleTitle', 'Head Chef');

        $this->postJson('/api/v1/employees', ['name' => 'Someone Else', 'phone' => $phone, 'role_title' => 'Waiter'])
            ->assertStatus(422);
    }

    public function test_an_employee_can_be_edited_with_a_partial_update(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage']);
        $employee = Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Rahim', 'phone' => '018'.rand(10000000, 99999999),
            'role_title' => 'Waiter', 'monthly_salary' => 15000,
        ]);

        // Only the salary is being changed -- must not require re-sending name/phone/role_title.
        $this->putJson("/api/v1/employees/{$employee->id}", ['monthly_salary' => 18000])
            ->assertStatus(200)->assertJsonPath('data.monthlySalary', 18000);
    }

    public function test_an_employee_with_payroll_history_cannot_be_deleted(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage', 'payroll.manage']);
        $employee = Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Karim', 'phone' => '019'.rand(10000000, 99999999),
            'role_title' => 'Cashier', 'monthly_salary' => 20000,
        ]);

        $period = $this->postJson('/api/v1/payroll/periods', [
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        ])->json('data');
        $this->postJson("/api/v1/payroll/periods/{$period['id']}/generate")->assertStatus(200);

        $this->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertStatus(422)->assertJsonPath('code', 'employee_has_payroll_history');
    }

    public function test_marking_attendance_upserts_rather_than_duplicating(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage']);
        $employee = Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Salma', 'phone' => '015'.rand(10000000, 99999999),
            'role_title' => 'Waiter', 'monthly_salary' => 15000,
        ]);
        $date = now()->toDateString();

        $this->postJson('/api/v1/attendance', ['employee_id' => $employee->id, 'date' => $date, 'status' => 'present'])
            ->assertStatus(201)->assertJsonPath('data.status', 'present');

        // Re-marking the same day corrects it instead of adding a second row.
        $this->postJson('/api/v1/attendance', ['employee_id' => $employee->id, 'date' => $date, 'status' => 'half_day'])
            ->assertStatus(201)->assertJsonPath('data.status', 'half_day');

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_the_daily_roster_shows_every_active_employee_even_if_unmarked(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage']);
        Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Unmarked Employee',
            'phone' => '014'.rand(10000000, 99999999), 'role_title' => 'Waiter', 'monthly_salary' => 15000,
        ]);

        $response = $this->getJson('/api/v1/attendance?date='.now()->toDateString());

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', null);
    }
}
