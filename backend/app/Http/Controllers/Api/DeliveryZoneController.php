<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveDeliveryZoneRequest;
use App\Models\AuditLog;
use App\Models\DeliveryZone;
use Illuminate\Http\Request;

class DeliveryZoneController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $zones = DeliveryZone::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $zones->map($this->serialize(...))]);
    }

    public function store(SaveDeliveryZoneRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select an active branch first.'], 422);
        }

        $zone = DeliveryZone::create([...$request->validated(), 'branch_id' => $branchId]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'delivery_zone.created',
            subjectType: DeliveryZone::class,
            subjectId: $zone->id,
            branchId: $zone->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($zone)], 201);
    }

    public function update(SaveDeliveryZoneRequest $request, DeliveryZone $deliveryZone)
    {
        $before = $deliveryZone->only(['name', 'delivery_fee', 'is_active']);
        $deliveryZone->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'delivery_zone.updated',
            subjectType: DeliveryZone::class,
            subjectId: $deliveryZone->id,
            branchId: $deliveryZone->branch_id,
            changes: ['before' => $before, 'after' => $deliveryZone->fresh()->only(['name', 'delivery_fee', 'is_active'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($deliveryZone->fresh())]);
    }

    public function destroy(Request $request, DeliveryZone $deliveryZone)
    {
        if ($deliveryZone->assignments()->exists()) {
            return response()->json([
                'code' => 'zone_has_deliveries',
                'message' => 'This zone has delivery history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }

        $deliveryZone->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'delivery_zone.deleted',
            subjectType: DeliveryZone::class,
            subjectId: $deliveryZone->id,
            branchId: $deliveryZone->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(DeliveryZone $zone): array
    {
        return [
            'id' => $zone->id,
            'branchId' => $zone->branch_id,
            'name' => $zone->name,
            'deliveryFee' => (float) $zone->delivery_fee,
            'isActive' => $zone->is_active,
        ];
    }
}
