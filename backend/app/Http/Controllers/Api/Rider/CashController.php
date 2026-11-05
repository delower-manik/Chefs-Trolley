<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\RiderCashCollection;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Manager side of rider cash: who is holding how much, and "handed over to the drawer". Needs cash.manage. */
class CashController extends Controller
{
    /** GET /cash/rider-holdings */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $rows = RiderCashCollection::with('rider:id,name,phone')->whereNull('settled_at')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('collected_at')->get();

        return response()->json(['data' => $rows->groupBy('rider_id')->map(fn ($g) => [
            'riderId' => $g->first()->rider_id, 'riderName' => $g->first()->rider?->name, 'phone' => $g->first()->rider?->phone,
            'holding' => Money::toFloat($g->sum(fn ($r) => Money::toMinor($r->amount))), 'deliveries' => $g->count(), 'oldest' => $g->min('collected_at'),
        ])->values()]);
    }

    /**
     * POST /cash/rider-holdings/{rider}/settle — the rider handed the cash in. Every unsettled collection joins YOUR open cash
     * shift (so the drawer's expected cash includes it) and is closed. Needs an open shift, like any cash intake.
     */
    public function settle(Request $request, string $rider)
    {
        $branchId = $request->attributes->get('branch_id');
        $r = Rider::where('id', $rider)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->firstOrFail();
        $shift = CashShift::where('branch_id', $r->branch_id)->where('opened_by', $request->user()->id)->where('status', 'open')->first();
        if (! $shift) {
            return response()->json(['code' => 'no_open_shift', 'message' => 'Open a cash register shift before receiving rider cash.'], 422);
        }

        $total = DB::transaction(function () use ($request, $r, $shift) {
            $rows = RiderCashCollection::where('rider_id', $r->id)->whereNull('settled_at')->lockForUpdate()->get();
            foreach ($rows as $c) {
                Payment::whereKey($c->payment_id)->update(['shift_id' => $shift->id]);
                $c->update(['settled_at' => now(), 'settled_by' => $request->user()->id]);
            }
            $total = $rows->sum(fn ($c) => Money::toMinor($c->amount));
            if ($rows->isEmpty()) {
                return 0;
            }
            AuditLog::record(
                actorId: $request->user()->id, actorName: $request->user()->name, action: 'rider.cash_settled', subjectType: Rider::class, subjectId: $r->id,
                branchId: $r->branch_id, changes: ['amount' => Money::toFloat($total), 'deliveries' => $rows->count(), 'shiftId' => $shift->id], ipAddress: $request->ip(),
            );

            return $total;
        });

        if ($total === 0) {
            return response()->json(['code' => 'nothing_to_settle', 'message' => 'This rider is not holding any cash.'], 422);
        }

        return response()->json(['data' => ['riderId' => $r->id, 'settled' => Money::toFloat($total)]]);
    }
}
