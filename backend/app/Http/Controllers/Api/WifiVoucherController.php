<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateVouchersRequest;
use App\Models\AuditLog;
use App\Models\WifiVoucher;
use App\Models\WifiZone;
use Illuminate\Http\Request;

/**
 * Voucher issuing and redemption -- deliberately just database
 * bookkeeping. Actually granting network access still requires real
 * router/captive-portal infrastructure (a MikroTik RouterOS API call, a
 * captive portal session, etc. per architecture §6); this only tracks
 * which codes exist and whether they've been used, which a real
 * integration would check against.
 */
class WifiVoucherController extends Controller
{
    public function index(Request $request, WifiZone $wifiZone)
    {
        $vouchers = $wifiZone->vouchers()->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $vouchers->map($this->serialize(...))]);
    }

    public function generate(GenerateVouchersRequest $request, WifiZone $wifiZone)
    {
        $count = $request->validated('count', 1);

        $vouchers = collect(range(1, $count))->map(fn () => WifiVoucher::create([
            'wifi_zone_id' => $wifiZone->id,
            'code' => WifiVoucher::generateCode(),
            'duration_minutes' => $request->validated('duration_minutes'),
            'created_by' => $request->user()->id,
        ]));

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'wifi_voucher.generated',
            subjectType: WifiZone::class,
            subjectId: $wifiZone->id,
            branchId: $wifiZone->branch_id,
            changes: ['count' => $count, 'durationMinutes' => $request->validated('duration_minutes')],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $vouchers->map($this->serialize(...))], 201);
    }

    /**
     * POST /wifi/vouchers/{wifiVoucher}/redeem
     * Marks a voucher used. A real captive portal would call this (or an
     * equivalent internal check) at the moment a guest actually connects.
     */
    public function redeem(Request $request, WifiVoucher $wifiVoucher)
    {
        if ($wifiVoucher->is_used) {
            return response()->json(['code' => 'voucher_already_used', 'message' => 'This voucher has already been redeemed.'], 422);
        }

        $wifiVoucher->update(['is_used' => true, 'used_at' => now()]);

        return response()->json(['data' => $this->serialize($wifiVoucher->fresh())]);
    }

    private function serialize(WifiVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'zoneId' => $voucher->wifi_zone_id,
            'code' => $voucher->code,
            'durationMinutes' => $voucher->duration_minutes,
            'isUsed' => $voucher->is_used,
            'usedAt' => $voucher->used_at,
            'createdAt' => $voucher->created_at,
        ];
    }
}
