<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEmployeeRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\Like;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $employees = Employee::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = Like::contains((string) $request->query('search'));
                $q->where(fn ($q) => $q->whereRaw(Like::clause('name'), [$term])->orWhereRaw(Like::clause('phone'), [$term]));
            })
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $employees->map($this->serialize(...))]);
    }

    public function store(SaveEmployeeRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select an active branch first.'], 422);
        }

        $employee = Employee::create([...$request->validated(), 'branch_id' => $branchId]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'employee.created',
            subjectType: Employee::class,
            subjectId: $employee->id,
            branchId: $employee->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($employee)], 201);
    }

    public function update(SaveEmployeeRequest $request, Employee $employee)
    {
        $before = $employee->only(['name', 'role_title', 'monthly_salary', 'is_active']);
        $employee->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'employee.updated',
            subjectType: Employee::class,
            subjectId: $employee->id,
            branchId: $employee->branch_id,
            changes: ['before' => $before, 'after' => $employee->fresh()->only(['name', 'role_title', 'monthly_salary', 'is_active'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($employee->fresh())]);
    }

    public function destroy(Request $request, Employee $employee)
    {
        if ($employee->payrollEntries()->exists()) {
            return response()->json([
                'code' => 'employee_has_payroll_history',
                'message' => 'This employee has payroll history and cannot be deleted. Mark them inactive instead.',
            ], 422);
        }

        $employee->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'employee.deleted',
            subjectType: Employee::class,
            subjectId: $employee->id,
            branchId: $employee->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'branchId' => $employee->branch_id,
            'userId' => $employee->user_id,
            'name' => $employee->name,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'roleTitle' => $employee->role_title,
            'employmentType' => $employee->employment_type,
            'monthlySalary' => (float) $employee->monthly_salary,
            'hireDate' => $employee->hire_date?->toDateString(),
            'isActive' => $employee->is_active,
        ];
    }
}
