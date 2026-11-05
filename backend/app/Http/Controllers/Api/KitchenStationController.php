<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveKitchenStationRequest;
use App\Models\KitchenStation;
use Illuminate\Http\Request;

class KitchenStationController extends Controller
{
    public function index(Request $request)
    {
        $stations = KitchenStation::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->withCount('foodItems')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return response()->json(['data' => $stations->map($this->serialize(...))]);
    }

    public function store(SaveKitchenStationRequest $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }

        $station = KitchenStation::create([...$request->validated(), 'branch_id' => $branchId]);
        $this->recordAudit($request, 'kitchen_station.created', $station);

        return response()->json(['data' => $this->serialize($station)], 201);
    }

    public function update(SaveKitchenStationRequest $request, KitchenStation $station)
    {
        $before = $station->only(['name', 'sort_order', 'is_active']);
        $station->update($request->validated());
        $station->refresh();
        $this->recordAudit($request, 'kitchen_station.updated', $station, ['before' => $before, 'after' => $station->only(array_keys($before))]);

        return response()->json(['data' => $this->serialize($station)]);
    }

    public function destroy(Request $request, KitchenStation $station)
    {
        // Dishes routed here fall back to "unrouted" (FK nullOnDelete);
        // lines on past orders keep their snapshot.
        $station->delete();
        $this->recordAudit($request, 'kitchen_station.deleted', $station);

        return response()->noContent();
    }

    private function serialize(KitchenStation $station): array
    {
        return [
            'id' => $station->id,
            'branchId' => $station->branch_id,
            'name' => $station->name,
            'sortOrder' => $station->sort_order,
            'isActive' => $station->is_active,
            'foodItemCount' => $station->food_items_count ?? null,
        ];
    }
}
