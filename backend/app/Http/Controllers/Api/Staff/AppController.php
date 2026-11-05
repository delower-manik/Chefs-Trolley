<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-service for restaurant staff. Everything is tied to the employee record linked to the signed-in user. Times are the
 * SERVER's clock (a phone's clock is never trusted), and a person can only clock themselves.
 */
class AppController extends Controller
{
    private const KITCHEN = ['new', 'accepted', 'preparing', 'ready', 'served', 'completed'];

    /** GET /staff/me */
    public function me(Request $request)
    {
        return response()->json(['data' => $this->profile($this->employee($request))]);
    }

    /** POST /staff/clock-in */
    public function clockIn(Request $request)
    {
        $e = $this->employee($request);
        $today = now()->toDateString();
        $record = AttendanceRecord::where('employee_id', $e->id)->whereDate('date', $today)->first();
        if ($record?->check_in_time) {
            return $this->error('already_clocked_in', 'You are already clocked in today.');
        }
        // A manager may have pre-marked the day as leave/absent: that is theirs to change, not the employee's.
        if ($record && $record->status !== 'present') {
            return $this->error('day_marked', 'Your manager has marked today as '.str_replace('_', ' ', $record->status).'. Ask them to correct it.');
        }
        $record = AttendanceRecord::updateOrCreate(
            ['employee_id' => $e->id, 'date' => $today],
            ['branch_id' => $e->branch_id, 'status' => 'present', 'check_in_time' => now()],
        );
        $this->audit($request, $e, 'attendance.clock_in', $record);

        return response()->json(['data' => $this->profile($e)]);
    }

    /** POST /staff/clock-out */
    public function clockOut(Request $request)
    {
        $e = $this->employee($request);
        $record = AttendanceRecord::where('employee_id', $e->id)->whereDate('date', now()->toDateString())->first();
        if (! $record?->check_in_time) {
            return $this->error('not_clocked_in', 'Clock in first.');
        }
        if ($record->check_out_time) {
            return $this->error('already_clocked_out', 'You already clocked out today.');
        }
        $record->update(['check_out_time' => now()]);
        $this->audit($request, $e, 'attendance.clock_out', $record);

        return response()->json(['data' => $this->profile($e)]);
    }

    /** GET /staff/attendance — the last 14 days of the employee's own record. */
    public function history(Request $request)
    {
        $e = $this->employee($request);
        $rows = AttendanceRecord::where('employee_id', $e->id)->orderByDesc('date')->limit(14)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'date' => $r->date->toDateString(), 'status' => $r->status, 'checkInTime' => $r->check_in_time, 'checkOutTime' => $r->check_out_time,
        ])]);
    }

    /** POST /staff/orders/{order}/served — front of house takes the ready items to the table. Kitchen-ready lines only. */
    public function served(Request $request, string $orderId)
    {
        $e = $this->employee($request);
        $result = DB::transaction(function () use ($request, $e, $orderId) {
            $order = Order::with('items')->where('id', $orderId)->where('branch_id', $e->branch_id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, ['cancelled', 'refunded', 'draft'], true)) {
                return $this->error('order_closed', 'This order is not active.');
            }
            $ready = $order->items->where('kitchen_state', 'ready');
            if ($ready->isEmpty()) {
                return $this->error('not_ready', 'Nothing on this order is ready yet.');
            }
            foreach ($ready as $item) {
                $item->update(['kitchen_state' => 'served']);
            }
            $order->load('items');
            $min = $order->items->map(fn ($i) => array_search($i->kitchen_state, self::KITCHEN, true))->min();
            $order->update(['kitchen_state' => self::KITCHEN[(int) $min]]);
            AuditLog::record(
                actorId: $request->user()->id, actorName: $request->user()->name, action: 'order.served', subjectType: Order::class, subjectId: $order->id,
                branchId: $order->branch_id, changes: ['by' => 'staff_app', 'lines' => $ready->count()], ipAddress: $request->ip(),
            );

            return $order;
        });

        return $result instanceof JsonResponse ? $result : response()->json(['data' => ['id' => $result->id, 'kitchenState' => $result->kitchen_state]]);
    }

    private function employee(Request $request): Employee
    {
        return $request->attributes->get('employee');
    }

    private function error(string $code, string $message): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], 422);
    }

    private function audit(Request $request, Employee $e, string $action, AttendanceRecord $r): void
    {
        AuditLog::record(
            actorId: $request->user()->id, actorName: $request->user()->name, action: $action, subjectType: AttendanceRecord::class, subjectId: $r->id,
            branchId: $e->branch_id, changes: ['employeeId' => $e->id, 'by' => 'staff_app'], ipAddress: $request->ip(),
        );
    }

    private function profile(Employee $e): array
    {
        $t = AttendanceRecord::where('employee_id', $e->id)->whereDate('date', now()->toDateString())->first();

        return [
            'id' => $e->id, 'name' => $e->name, 'roleTitle' => $e->role_title, 'branchId' => $e->branch_id,
            'today' => ['status' => $t?->status, 'checkInTime' => $t?->check_in_time, 'checkOutTime' => $t?->check_out_time],
            'clockedIn' => (bool) ($t?->check_in_time && ! $t->check_out_time),
        ];
    }
}
