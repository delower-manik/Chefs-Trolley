<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveFloorPlanLayoutRequest;
use App\Http\Requests\SaveFloorPlanRequest;
use App\Models\DiningTable;
use App\Models\FloorPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FloorPlanController extends Controller
{
    public function index(Request $request)
    {
        $plans = FloorPlan::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->with('tables')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return response()->json(['data' => $plans->map($this->serialize(...))]);
    }

    public function store(SaveFloorPlanRequest $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }

        $plan = FloorPlan::create([...$request->validated(), 'branch_id' => $branchId]);
        $this->recordAudit($request, 'floor_plan.created', $plan);

        return response()->json(['data' => $this->serialize($plan->load('tables'))], 201);
    }

    public function update(SaveFloorPlanRequest $request, FloorPlan $floorPlan)
    {
        $before = $floorPlan->only(['name', 'width', 'height', 'is_active']);
        $floorPlan->update($request->validated());
        $floorPlan->refresh();
        $this->recordAudit($request, 'floor_plan.updated', $floorPlan, ['before' => $before, 'after' => $floorPlan->only(array_keys($before))]);

        return response()->json(['data' => $this->serialize($floorPlan->load('tables'))]);
    }

    public function destroy(Request $request, FloorPlan $floorPlan)
    {
        // Tables on it are kept (FK nullOnDelete) and become "unplaced".
        $floorPlan->delete();
        $this->recordAudit($request, 'floor_plan.deleted', $floorPlan);

        return response()->noContent();
    }

    /**
     * PUT /floor-plans/{floorPlan}/layout
     * Saves the editor's drag-and-drop result in one transaction: places the
     * given tables on this plan with their position/size/shape. Tables must
     * belong to the plan's branch; the client cannot move another branch's
     * tables. Tables not listed are left where they are.
     */
    public function saveLayout(SaveFloorPlanLayoutRequest $request, FloorPlan $floorPlan)
    {
        $entries = collect($request->validated('tables'))->keyBy('id');

        $tables = DiningTable::where('branch_id', $floorPlan->branch_id)->whereIn('id', $entries->keys())->get();
        if ($tables->count() !== $entries->count()) {
            return response()->json(['code' => 'invalid_table', 'message' => 'One or more tables do not belong to this branch.'], 422);
        }

        DB::transaction(function () use ($tables, $entries, $floorPlan) {
            foreach ($tables as $table) {
                $e = $entries[$table->id];
                $table->update(array_filter([
                    'floor_plan_id' => $floorPlan->id,
                    'pos_x' => $e['pos_x'],
                    'pos_y' => $e['pos_y'],
                    'width' => $e['width'] ?? null,
                    'height' => $e['height'] ?? null,
                    'shape' => $e['shape'] ?? null,
                ], fn ($v) => $v !== null));
            }
        });

        $this->recordAudit($request, 'floor_plan.layout_saved', $floorPlan, ['tables' => $entries->count()]);

        return response()->json(['data' => $this->serialize($floorPlan->load('tables'))]);
    }

    private function serialize(FloorPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'branchId' => $plan->branch_id,
            'name' => $plan->name,
            'width' => $plan->width,
            'height' => $plan->height,
            'sortOrder' => $plan->sort_order,
            'isActive' => $plan->is_active,
            'tables' => $plan->tables->sortBy('label')->values()->map(fn ($t) => [
                'id' => $t->id,
                'label' => $t->label,
                'capacity' => $t->capacity,
                'status' => $t->status,
                'posX' => $t->pos_x,
                'posY' => $t->pos_y,
                'width' => $t->width,
                'height' => $t->height,
                'shape' => $t->shape,
            ]),
        ];
    }
}
