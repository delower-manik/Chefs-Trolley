<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\User;
use App\Support\EscPos;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Cash register: one open shift per cashier per branch; cash payments/refunds attach to it. */
class CashShiftController extends Controller
{
    /** GET /cash/shifts — recent shifts of the active branch. */
    public function index(Request $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }

        $shifts = CashShift::where('branch_id', $branchId)->latest('opened_at')->limit(50)->get();

        return response()->json(['data' => $shifts->map(fn ($s) => $this->serialize($s))]);
    }

    /** GET /cash/shifts/current — the caller's open shift, or null. */
    public function current(Request $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }
        $shift = CashShift::where('branch_id', $branchId)->where('opened_by', $request->user()->id)->where('status', 'open')->first();

        return response()->json(['data' => $shift ? $this->serialize($shift, true) : null]);
    }

    /** POST /cash/shifts/open {opening_float} */
    public function open(Request $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }
        $data = $request->validate(['opening_float' => ['required', 'numeric', 'min:0', 'max:10000000']]);

        // Lock the cashier's user row so two simultaneous "open" taps cannot both pass the check.
        $shift = DB::transaction(function () use ($request, $branchId, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->first();
            if (CashShift::where('branch_id', $branchId)->where('opened_by', $request->user()->id)->where('status', 'open')->exists()) {
                return null;
            }

            return CashShift::create([
                'branch_id' => $branchId, 'opened_by' => $request->user()->id,
                'opening_float' => Money::toDecimal(Money::toMinor($data['opening_float'])), 'opened_at' => now(), 'status' => 'open',
            ]);
        });
        if (! $shift) {
            return response()->json(['code' => 'shift_already_open', 'message' => 'You already have an open shift.'], 422);
        }
        $this->recordAudit($request, 'cash.shift_opened', $shift, ['opening_float' => (float) $shift->opening_float]);

        return response()->json(['data' => $this->serialize($shift, true)], 201);
    }

    /** POST /cash/shifts/{shift}/movements {type: in|out, amount, reason} */
    public function movement(Request $request, CashShift $shift)
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        // The shift row is locked for the check-and-write so concurrent pay-outs cannot overdraw the drawer or hit a closing shift.
        return DB::transaction(function () use ($request, $shift, $data) {
            $shift = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($refused = $this->requireOwnOpenShift($request, $shift)) {
                return $refused;
            }

            $amount = Money::toMinor($data['amount']);
            if ($data['type'] === 'out' && $amount > $this->expectedMinor($shift)) {
                return response()->json(['code' => 'insufficient_drawer_cash', 'message' => 'There is not that much cash in the drawer.'], 422);
            }

            $m = CashMovement::create([
                'shift_id' => $shift->id, 'branch_id' => $shift->branch_id, 'type' => $data['type'],
                'amount' => Money::toDecimal($amount), 'reason' => $data['reason'], 'created_by' => $request->user()->id,
            ]);
            $this->recordAudit($request, 'cash.movement', $shift, ['type' => $m->type, 'amount' => (float) $m->amount, 'reason' => $m->reason]);

            return response()->json(['data' => $this->serialize($shift->fresh(), true)], 201);
        });
    }

    /** POST /cash/shifts/{shift}/close {counted_cash, notes?} */
    public function close(Request $request, CashShift $shift)
    {
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0', 'max:100000000'], 'notes' => ['nullable', 'string', 'max:200']]);

        return DB::transaction(function () use ($request, $shift, $data) {
            $shift = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($refused = $this->requireOwnOpenShift($request, $shift)) {
                return $refused;
            }
            $expected = $this->expectedMinor($shift);
            $counted = Money::toMinor($data['counted_cash']);
            $shift->update([
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id,
                'expected_cash' => Money::toDecimal($expected), 'counted_cash' => Money::toDecimal($counted),
                'variance' => Money::toDecimal($counted - $expected), 'notes' => $data['notes'] ?? null,
            ]);
            $this->recordAudit($request, 'cash.shift_closed', $shift, [
                'expected' => Money::toFloat($expected), 'counted' => Money::toFloat($counted), 'variance' => Money::toFloat($counted - $expected),
            ]);

            return response()->json(['data' => $this->serialize($shift->fresh(), true)]);
        });
    }

    /** GET /cash/shifts/{shift}/report — the Z-report. */
    public function report(CashShift $shift)
    {
        return response()->json(['data' => $this->serialize($shift, true)]);
    }

    /** POST /cash/drawer/open — ESC/POS pulse for a local print agent / browser bridge. Audited. */
    public function drawer(Request $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }
        $shift = CashShift::where('branch_id', $branchId)->where('opened_by', $request->user()->id)->where('status', 'open')->first();
        if (! $shift) {
            return response()->json(['code' => 'no_open_shift', 'message' => 'Open a shift first.'], 422);
        }
        $this->recordAudit($request, 'cash.drawer_opened', $shift);

        return response()->json(['data' => ['escposBase64' => base64_encode((new EscPos)->init()->openDrawer()->bytes())]]);
    }

    private function requireOwnOpenShift(Request $request, CashShift $shift)
    {
        if ($shift->status !== 'open') {
            return response()->json(['code' => 'shift_closed', 'message' => 'This shift is already closed.'], 422);
        }
        if ($shift->opened_by !== $request->user()->id && ! $request->user()->hasPermissionKey('settings.manage')) {
            return response()->json(['code' => 'not_your_shift', 'message' => 'Only the cashier who opened this shift can change it.'], 403);
        }

        return null;
    }

    /** Cash that should be in the drawer: float + cash taken (incl. tips) − cash refunded + movements in − out. */
    private function expectedMinor(CashShift $shift): int
    {
        $expected = Money::toMinor($shift->opening_float);
        foreach (Payment::where('shift_id', $shift->id)->where('method', 'cash')->whereNull('voided_at')->get() as $p) {
            $expected += $p->kind === 'payment' ? Money::toMinor($p->amount) + Money::toMinor($p->tip) : -Money::toMinor($p->amount);
        }
        foreach ($shift->movements as $m) {
            $expected += $m->type === 'in' ? Money::toMinor($m->amount) : -Money::toMinor($m->amount);
        }

        return $expected;
    }

    private function serialize(CashShift $s, bool $detail = false): array
    {
        $out = [
            'id' => $s->id, 'branchId' => $s->branch_id, 'status' => $s->status, 'openedBy' => $s->opened_by,
            'openingFloat' => (float) $s->opening_float, 'openedAt' => $s->opened_at?->toIso8601String(),
            'closedAt' => $s->closed_at?->toIso8601String(),
            'expectedCash' => $s->expected_cash === null ? null : (float) $s->expected_cash,
            'countedCash' => $s->counted_cash === null ? null : (float) $s->counted_cash,
            'variance' => $s->variance === null ? null : (float) $s->variance, 'notes' => $s->notes,
        ];
        if (! $detail) {
            return $out;
        }

        $byMethod = [];
        $voids = 0;
        foreach (Payment::where('shift_id', $s->id)->get() as $p) {
            if ($p->voided_at) {
                $voids++;

                continue;
            }
            $row = $byMethod[$p->method] ??= ['payments' => 0, 'tips' => 0, 'refunds' => 0, 'count' => 0];
            if ($p->kind === 'payment') {
                $row['payments'] += Money::toMinor($p->amount);
                $row['tips'] += Money::toMinor($p->tip);
                $row['count']++;
            } else {
                $row['refunds'] += Money::toMinor($p->amount);
            }
            $byMethod[$p->method] = $row;
        }

        $out['byMethod'] = collect($byMethod)->map(fn ($r, $m) => [
            'method' => $m, 'payments' => Money::toFloat($r['payments']), 'tips' => Money::toFloat($r['tips']),
            'refunds' => Money::toFloat($r['refunds']), 'net' => Money::toFloat($r['payments'] - $r['refunds']), 'count' => $r['count'],
        ])->values();
        $out['voidedPayments'] = $voids;
        $out['movements'] = $s->movements()->orderBy('created_at')->get()->map(fn ($m) => [
            'id' => $m->id, 'type' => $m->type, 'amount' => (float) $m->amount, 'reason' => $m->reason,
            'createdAt' => $m->created_at?->toIso8601String(),
        ]);
        $out['expectedNow'] = Money::toFloat($this->expectedMinor($s));

        return $out;
    }
}
