<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveWifiZoneRequest;
use App\Models\AuditLog;
use App\Models\WifiZone;
use Illuminate\Http\Request;

class WifiZoneController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $zones = WifiZone::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $zones->map($this->serialize(...))]);
    }

    public function store(SaveWifiZoneRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select an active branch first.'], 422);
        }

        $zone = WifiZone::create([...$request->validated(), 'branch_id' => $branchId]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'wifi_zone.created',
            subjectType: WifiZone::class,
            subjectId: $zone->id,
            branchId: $zone->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($zone)], 201);
    }

    public function update(SaveWifiZoneRequest $request, WifiZone $wifiZone)
    {
        $wifiZone->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'wifi_zone.updated',
            subjectType: WifiZone::class,
            subjectId: $wifiZone->id,
            branchId: $wifiZone->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($wifiZone->fresh())]);
    }

    public function destroy(Request $request, WifiZone $wifiZone)
    {
        $wifiZone->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'wifi_zone.deleted',
            subjectType: WifiZone::class,
            subjectId: $wifiZone->id,
            branchId: $wifiZone->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(WifiZone $zone): array
    {
        return [
            'id' => $zone->id,
            'branchId' => $zone->branch_id,
            'name' => $zone->name,
            'ssid' => $zone->ssid,
            'accessType' => $zone->access_type,
            'defaultSessionMinutes' => $zone->default_session_minutes,
            'isActive' => $zone->is_active,
        ];
    }
}
