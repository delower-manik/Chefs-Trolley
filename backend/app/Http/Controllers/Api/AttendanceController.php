<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * GET /attendance?date=YYYY-MM-DD
     * A daily roster: every active employee in the branch, with whatever
     * attendance record exists for that date -- or `null` if nobody has
     * marked it yet, rather than silently assuming "absent" for someone
     * who just hasn't been checked off yet.
     */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $date = $request->query('date', now()->toDateString());

        $employees = Employee::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $records = AttendanceRecord::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('date', $date)
            ->get()
            ->keyBy('employee_id');

        return response()->json([
            'data' => $employees->map(function ($employee) use ($records, $date) {
                $record = $records->get($employee->id);

                return [
                    'employeeId' => $employee->id,
                    'employeeName' => $employee->name,
                    'roleTitle' => $employee->role_title,
                    'date' => $date,
                    'status' => $record?->status,
                    'checkInTime' => $record?->check_in_time,
                    'checkOutTime' => $record?->check_out_time,
                    'notes' => $record?->notes,
                ];
            }),
        ]);
    }

    /**
     * GET /attendance/employee/{employee}
     * One employee's recent attendance history, for their profile view.
     */
    public function forEmployee(Employee $employee)
    {
        $records = $employee->attendanceRecords()->orderByDesc('date')->limit(30)->get();

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id' => $r->id,
                'date' => $r->date->toDateString(),
                'status' => $r->status,
                'checkInTime' => $r->check_in_time,
                'checkOutTime' => $r->check_out_time,
                'notes' => $r->notes,
            ]),
        ]);
    }

    /**
     * POST /attendance
     * Marks (or re-marks) one employee's attendance for one date --
     * upserted on (employee_id, date) so re-submitting the same day just
     * corrects it rather than creating a duplicate row.
     */
    public function store(SaveAttendanceRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $employee = Employee::findOrFail($request->validated('employee_id'));

        if ($branchId && $employee->branch_id !== $branchId) {
            return response()->json(['code' => 'wrong_branch', 'message' => 'This employee belongs to a different branch.'], 422);
        }

        $record = AttendanceRecord::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $request->validated('date')],
            [
                'branch_id' => $employee->branch_id,
                'status' => $request->validated('status'),
                'check_in_time' => $request->validated('check_in_time'),
                'check_out_time' => $request->validated('check_out_time'),
                'notes' => $request->validated('notes'),
            ]
        );

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'attendance.marked',
            subjectType: AttendanceRecord::class,
            subjectId: $record->id,
            branchId: $record->branch_id,
            changes: ['employeeId' => $employee->id, 'date' => $record->date->toDateString(), 'status' => $record->status],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => [
            'id' => $record->id,
            'employeeId' => $record->employee_id,
            'date' => $record->date->toDateString(),
            'status' => $record->status,
            'checkInTime' => $record->check_in_time,
            'checkOutTime' => $record->check_out_time,
            'notes' => $record->notes,
        ]], 201);
    }
}
