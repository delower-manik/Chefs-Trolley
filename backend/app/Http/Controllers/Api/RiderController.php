<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveRiderRequest;
use App\Models\AuditLog;
use App\Models\Rider;
use Illuminate\Http\Request;

class RiderController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $riders = Rider::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $riders->map($this->serialize(...))]);
    }

    public function store(SaveRiderRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select an active branch first.'], 422);
        }

        $rider = Rider::create([...$request->validated(), 'branch_id' => $branchId]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'rider.created',
            subjectType: Rider::class,
            subjectId: $rider->id,
            branchId: $rider->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($rider)], 201);
    }

    public function update(SaveRiderRequest $request, Rider $rider)
    {
        $before = $rider->only(['name', 'status', 'is_active']);
        $rider->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'rider.updated',
            subjectType: Rider::class,
            subjectId: $rider->id,
            branchId: $rider->branch_id,
            changes: ['before' => $before, 'after' => $rider->fresh()->only(['name', 'status', 'is_active'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($rider->fresh())]);
    }

    public function destroy(Request $request, Rider $rider)
    {
        if ($rider->assignments()->whereIn('status', ['pending', 'assigned', 'picked_up'])->exists()) {
            return response()->json([
                'code' => 'rider_has_active_deliveries',
                'message' => 'This rider has an active delivery in progress and cannot be removed.',
            ], 422);
        }

        $rider->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'rider.deleted',
            subjectType: Rider::class,
            subjectId: $rider->id,
            branchId: $rider->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Rider $rider): array
    {
        return [
            'id' => $rider->id,
            'branchId' => $rider->branch_id,
            'name' => $rider->name,
            'phone' => $rider->phone,
            'vehicleType' => $rider->vehicle_type,
            'status' => $rider->status,
            'isActive' => $rider->is_active,
            'userId' => $rider->user_id,
            // Only while they are sharing AND the fix is recent; a stale dot is worse than none.
            'location' => $rider->location_sharing && $rider->last_located_at?->gt(now()->subMinutes(10))
                ? ['lat' => (float) $rider->last_lat, 'lng' => (float) $rider->last_lng, 'at' => $rider->last_located_at] : null,
        ];
    }
}
