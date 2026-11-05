<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\PayrollPeriod;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    public function test_generating_payroll_deducts_for_real_absences(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage', 'payroll.manage']);
        $employee = Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Nasrin', 'phone' => '013'.rand(10000000, 99999999),
            'role_title' => 'Waiter', 'monthly_salary' => 30000,
        ]);

        $periodStart = now()->startOfMonth();
        $periodEnd = now()->startOfMonth()->addDays(9); // a 10-day window for easy math

        // 2 absences inside the window
        AttendanceRecord::create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'date' => $periodStart->copy()->addDays(2), 'status' => 'absent']);
        AttendanceRecord::create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'date' => $periodStart->copy()->addDays(4), 'status' => 'absent']);
        // An absence outside the window must not count.
        AttendanceRecord::create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'date' => $periodEnd->copy()->addDays(5), 'status' => 'absent']);

        $period = $this->postJson('/api/v1/payroll/periods', [
            'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString(),
        ])->json('data');

        $response = $this->postJson("/api/v1/payroll/periods/{$period['id']}/generate");

        $dailyRate = round(30000 / 30, 2); // 1000.00
        $response->assertStatus(200)
            ->assertJsonPath('data.entries.0.absentDays', 2)
            ->assertJsonPath('data.entries.0.deductions', $this->num(round($dailyRate * 2, 2)))
            ->assertJsonPath('data.entries.0.netPay', $this->num(round(30000 - $dailyRate * 2, 2)));
    }

    public function test_regenerating_a_draft_period_replaces_entries_not_duplicates_them(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage', 'payroll.manage']);
        Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Employee One',
            'phone' => '012'.rand(10000000, 99999999), 'role_title' => 'Cook', 'monthly_salary' => 20000,
        ]);

        $period = $this->postJson('/api/v1/payroll/periods', [
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        ])->json('data');

        $this->postJson("/api/v1/payroll/periods/{$period['id']}/generate")->assertStatus(200);
        $this->postJson("/api/v1/payroll/periods/{$period['id']}/generate")->assertStatus(200);

        $this->assertDatabaseCount('payroll_entries', 1);
    }

    public function test_a_bonus_can_be_added_while_draft_and_recomputes_net_pay(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage', 'payroll.manage']);
        Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Bonus Employee',
            'phone' => '011'.rand(10000000, 99999999), 'role_title' => 'Cook', 'monthly_salary' => 20000,
        ]);

        $period = $this->postJson('/api/v1/payroll/periods', [
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        ])->json('data');
        $entryId = $this->postJson("/api/v1/payroll/periods/{$period['id']}/generate")->json('data.entries.0.id');

        $this->putJson("/api/v1/payroll/entries/{$entryId}", ['bonuses' => 2000])
            ->assertStatus(200)->assertJsonPath('data.netPay', 22000);
    }

    public function test_full_lifecycle_draft_to_finalized_to_paid_creates_an_expense(): void
    {
        $user = $this->actingAsUserWithPermissions(['hr.manage', 'payroll.manage', 'finance.manage']);
        Employee::create([
            'branch_id' => $user->activeBranch->id, 'name' => 'Paid Employee',
            'phone' => '010'.rand(10000000, 99999999), 'role_title' => 'Manager', 'monthly_salary' => 40000,
        ]);

        $period = $this->postJson('/api/v1/payroll/periods', [
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        ])->json('data');

        // Can't finalize before generating any entries.
        $this->postJson("/api/v1/payroll/periods/{$period['id']}/finalize")->assertStatus(422);

        $this->postJson("/api/v1/payroll/periods/{$period['id']}/generate")->assertStatus(200);

        // Can't pay before finalizing.
        $this->postJson("/api/v1/payroll/periods/{$period['id']}/pay")->assertStatus(422);

        $this->postJson("/api/v1/payroll/periods/{$period['id']}/finalize")
            ->assertStatus(200)->assertJsonPath('data.status', 'finalized');

        // Can't regenerate once finalized.
        $this->postJson("/api/v1/payroll/periods/{$period['id']}/generate")->assertStatus(422);

        $this->postJson("/api/v1/payroll/periods/{$period['id']}/pay")
            ->assertStatus(200)->assertJsonPath('data.status', 'paid');

        $expense = Expense::where('reference_type', PayrollPeriod::class)->first();
        $this->assertNotNull($expense, 'paying payroll should create an expense record');
        $this->assertEquals(40000, (float) $expense->amount);
        $this->assertEquals('Salaries', $expense->category->name);

        // A system-generated expense can't be edited or deleted directly.
        $this->putJson("/api/v1/expenses/{$expense->id}", ['amount' => 1, 'category_id' => $expense->category_id, 'expense_date' => now()->toDateString()])
            ->assertStatus(422)->assertJsonPath('code', 'expense_is_system_generated');
    }
}
