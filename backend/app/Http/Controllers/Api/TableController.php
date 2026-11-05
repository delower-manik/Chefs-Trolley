<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveTableRequest;
use App\Models\AuditLog;
use App\Models\DiningTable;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $tables = DiningTable::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('label')
            ->get();

        return response()->json(['data' => $tables->map($this->serialize(...))]);
    }

    public function store(SaveTableRequest $request)
    {
        $table = DiningTable::create($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'table.created',
            subjectType: DiningTable::class,
            subjectId: $table->id,
            branchId: $table->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($table)], 201);
    }

    public function update(SaveTableRequest $request, DiningTable $table)
    {
        $before = $table->only(['status', 'capacity', 'label']);
        $table->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'table.updated',
            subjectType: DiningTable::class,
            subjectId: $table->id,
            branchId: $table->branch_id,
            changes: ['before' => $before, 'after' => $table->only(['status', 'capacity', 'label'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($table->fresh())]);
    }

    public function destroy(Request $request, DiningTable $table)
    {
        $table->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'table.deleted',
            subjectType: DiningTable::class,
            subjectId: $table->id,
            branchId: $table->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(DiningTable $table): array
    {
        return [
            'id' => $table->id,
            'branchId' => $table->branch_id,
            'label' => $table->label,
            'capacity' => $table->capacity,
            'status' => $table->status,
            'posX' => $table->pos_x,
            'posY' => $table->pos_y,
            'floorPlanId' => $table->floor_plan_id,
            'width' => $table->width,
            'height' => $table->height,
            'shape' => $table->shape,
        ];
    }
}
