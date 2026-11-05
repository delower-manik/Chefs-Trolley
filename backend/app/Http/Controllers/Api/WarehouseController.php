<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveWarehouseRequest;
use App\Models\AuditLog;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        // Guarantees every branch has at least one warehouse to work with,
        // without forcing a manual setup step before Purchases/Adjustments
        // can be used for the first time.
        if ($branchId) {
            $request->user()->activeBranch?->defaultWarehouse();
        }

        $warehouses = Warehouse::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $warehouses->map($this->serialize(...))]);
    }

    public function store(SaveWarehouseRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json([
                'code' => 'branch_required',
                'message' => 'Select an active branch before adding a warehouse.',
            ], 422);
        }

        $warehouse = DB::transaction(function () use ($request, $branchId) {
            if ($request->boolean('is_default')) {
                Warehouse::where('branch_id', $branchId)->update(['is_default' => false]);
            }

            return Warehouse::create([
                'branch_id' => $branchId,
                'name' => $request->validated('name'),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'warehouse.created',
            subjectType: Warehouse::class,
            subjectId: $warehouse->id,
            branchId: $warehouse->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($warehouse)], 201);
    }

    public function update(SaveWarehouseRequest $request, Warehouse $warehouse)
    {
        DB::transaction(function () use ($request, $warehouse) {
            if ($request->boolean('is_default')) {
                Warehouse::where('branch_id', $warehouse->branch_id)->where('id', '!=', $warehouse->id)
                    ->update(['is_default' => false]);
            }

            $warehouse->update($request->validated());
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'warehouse.updated',
            subjectType: Warehouse::class,
            subjectId: $warehouse->id,
            branchId: $warehouse->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($warehouse->fresh())]);
    }

    public function destroy(Request $request, Warehouse $warehouse)
    {
        if ($warehouse->stocks()->where('quantity', '>', 0)->exists()) {
            return response()->json([
                'code' => 'warehouse_not_empty',
                'message' => 'This warehouse still holds stock. Transfer it out before deleting.',
            ], 422);
        }

        if ($warehouse->is_default) {
            return response()->json([
                'code' => 'cannot_delete_default_warehouse',
                'message' => 'Make another warehouse the default before deleting this one.',
            ], 422);
        }

        $warehouse->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'warehouse.deleted',
            subjectType: Warehouse::class,
            subjectId: $warehouse->id,
            branchId: $warehouse->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'branchId' => $warehouse->branch_id,
            'name' => $warehouse->name,
            'isDefault' => $warehouse->is_default,
        ];
    }
}
