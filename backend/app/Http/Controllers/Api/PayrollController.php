<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePayrollPeriodRequest;
use App\Http\Requests\UpdatePayrollEntryRequest;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $periods = PayrollPeriod::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->withCount('entries')
            ->withSum('entries as total_net_pay', 'net_pay')
            ->orderByDesc('period_start')
            ->get();

        return response()->json(['data' => $periods->map($this->serializePeriod(...))]);
    }

    public function store(CreatePayrollPeriodRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select an active branch first.'], 422);
        }

        $period = PayrollPeriod::create([
            ...$request->validated(),
            'branch_id' => $branchId,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'payroll_period.created',
            subjectType: PayrollPeriod::class,
            subjectId: $period->id,
            branchId: $period->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serializePeriod($period->loadCount('entries'))], 201);
    }

    public function show(PayrollPeriod $payrollPeriod)
    {
        $payrollPeriod->load('entries.employee');

        return response()->json(['data' => $this->serializePeriodWithEntries($payrollPeriod)]);
    }

    /**
     * POST /payroll/periods/{payrollPeriod}/generate
     * (Re)computes one entry per active employee in the branch: absent
     * days are counted from real AttendanceRecord rows within the period
     * window and deducted at the employee's daily rate. Safe to call
     * repeatedly while still a draft -- it replaces the previous entries
     * rather than stacking duplicates, so correcting attendance and
     * re-generating just works.
     */
    public function generate(Request $request, PayrollPeriod $payrollPeriod)
    {
        if (! $payrollPeriod->isGeneratable()) {
            return response()->json([
                'code' => 'invalid_payroll_state',
                'message' => 'This payroll period is no longer a draft and cannot be regenerated.',
            ], 422);
        }

        DB::transaction(function () use ($payrollPeriod) {
            $payrollPeriod->entries()->delete();

            $employees = Employee::where('branch_id', $payrollPeriod->branch_id)->where('is_active', true)->get();

            foreach ($employees as $employee) {
                $absentDays = AttendanceRecord::where('employee_id', $employee->id)
                    ->whereBetween('date', [$payrollPeriod->period_start->toDateString(), $payrollPeriod->period_end->toDateString()])
                    ->where('status', 'absent')
                    ->count();

                $deductionsMinor = $absentDays * $employee->dailyRateMinor();
                $deductions = Money::toDecimal($deductionsMinor);
                $netPay = Money::toDecimal(max(0, Money::toMinor($employee->monthly_salary) - $deductionsMinor));

                PayrollEntry::create([
                    'payroll_period_id' => $payrollPeriod->id,
                    'employee_id' => $employee->id,
                    'base_salary' => $employee->monthly_salary,
                    'absent_days' => $absentDays,
                    'deductions' => $deductions,
                    'bonuses' => 0,
                    'net_pay' => $netPay,
                ]);
            }
        });

        return response()->json(['data' => $this->serializePeriodWithEntries($payrollPeriod->fresh(['entries.employee']))]);
    }

    /**
     * PUT /payroll/entries/{payrollEntry}
     * The only thing editable per-entry after generation: a manual bonus.
     * Recomputes net_pay; only allowed while the period is still a draft.
     */
    public function updateEntry(UpdatePayrollEntryRequest $request, PayrollEntry $payrollEntry)
    {
        $period = $payrollEntry->period;
        if (! $period->isGeneratable()) {
            return response()->json([
                'code' => 'invalid_payroll_state',
                'message' => 'This payroll period is no longer a draft.',
            ], 422);
        }

        $bonuses = Money::toDecimal(Money::toMinor($request->validated('bonuses')));
        $netPay = Money::toDecimal(max(0, Money::toMinor($payrollEntry->base_salary) - Money::toMinor($payrollEntry->deductions) + Money::toMinor($bonuses)));

        $payrollEntry->update(['bonuses' => $bonuses, 'net_pay' => $netPay]);

        return response()->json(['data' => $this->serializeEntry($payrollEntry->fresh('employee'))]);
    }

    /**
     * POST /payroll/periods/{payrollPeriod}/finalize
     */
    public function finalize(Request $request, PayrollPeriod $payrollPeriod)
    {
        if (! $payrollPeriod->isFinalizable()) {
            return response()->json([
                'code' => 'invalid_payroll_state',
                'message' => 'Generate at least one entry before finalizing this payroll period.',
            ], 422);
        }

        $payrollPeriod->update(['status' => 'finalized', 'finalized_at' => now()]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'payroll_period.finalized',
            subjectType: PayrollPeriod::class,
            subjectId: $payrollPeriod->id,
            branchId: $payrollPeriod->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serializePeriod($payrollPeriod->fresh()->loadCount('entries'))]);
    }

    /**
     * POST /payroll/periods/{payrollPeriod}/pay
     * Marks the period paid and records one aggregate Expense for the
     * total net pay, under an auto-created "Salaries" category -- so
     * payroll actually shows up in the Accounting summary rather than
     * living in a silo.
     */
    public function pay(Request $request, PayrollPeriod $payrollPeriod)
    {
        if (! $payrollPeriod->isPayable()) {
            return response()->json([
                'code' => 'invalid_payroll_state',
                'message' => 'This payroll period must be finalized before it can be paid.',
            ], 422);
        }

        $totalNetPay = (float) $payrollPeriod->entries()->sum('net_pay');

        DB::transaction(function () use ($payrollPeriod, $totalNetPay, $request) {
            $category = ExpenseCategory::firstOrCreate(
                ['branch_id' => $payrollPeriod->branch_id, 'name' => 'Salaries']
            );

            Expense::create([
                'branch_id' => $payrollPeriod->branch_id,
                'category_id' => $category->id,
                'amount' => $totalNetPay,
                'description' => "Payroll for {$payrollPeriod->period_start->toDateString()} to {$payrollPeriod->period_end->toDateString()}",
                'expense_date' => now()->toDateString(),
                'created_by' => $request->user()->id,
                'reference_type' => PayrollPeriod::class,
                'reference_id' => $payrollPeriod->id,
            ]);

            $payrollPeriod->update(['status' => 'paid', 'paid_at' => now()]);
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'payroll_period.paid',
            subjectType: PayrollPeriod::class,
            subjectId: $payrollPeriod->id,
            branchId: $payrollPeriod->branch_id,
            changes: ['totalNetPay' => $totalNetPay],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serializePeriod($payrollPeriod->fresh()->loadCount('entries'))]);
    }

    private function serializePeriod(PayrollPeriod $period): array
    {
        return [
            'id' => $period->id,
            'branchId' => $period->branch_id,
            'periodStart' => $period->period_start->toDateString(),
            'periodEnd' => $period->period_end->toDateString(),
            'status' => $period->status,
            'entryCount' => $period->entries_count ?? $period->entries()->count(),
            'totalNetPay' => (float) ($period->total_net_pay ?? $period->entries()->sum('net_pay')),
            'finalizedAt' => $period->finalized_at,
            'paidAt' => $period->paid_at,
        ];
    }

    private function serializePeriodWithEntries(PayrollPeriod $period): array
    {
        return [
            ...$this->serializePeriod($period),
            'entries' => $period->entries->map($this->serializeEntry(...)),
        ];
    }

    private function serializeEntry(PayrollEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'employeeId' => $entry->employee_id,
            'employeeName' => $entry->employee?->name,
            'baseSalary' => (float) $entry->base_salary,
            'absentDays' => $entry->absent_days,
            'deductions' => (float) $entry->deductions,
            'bonuses' => (float) $entry->bonuses,
            'netPay' => (float) $entry->net_pay,
        ];
    }
}
