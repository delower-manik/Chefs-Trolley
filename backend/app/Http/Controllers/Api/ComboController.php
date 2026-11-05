<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveComboRequest;
use App\Models\Combo;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComboController extends Controller
{
    public function index(Request $request)
    {
        $combos = Combo::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->with('items.foodItem')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $combos->map($this->serialize(...))]);
    }

    public function store(SaveComboRequest $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }

        $combo = DB::transaction(function () use ($request, $branchId) {
            $combo = Combo::create([...$request->safe()->except('items'), 'branch_id' => $branchId]);
            $combo->items()->createMany($request->validated('items'));

            return $combo;
        });

        $this->recordAudit($request, 'combo.created', $combo, ['after' => $combo->only(['name', 'price'])]);

        return response()->json(['data' => $this->serialize($combo->load('items.foodItem'))], 201);
    }

    public function update(SaveComboRequest $request, Combo $combo)
    {
        $before = $combo->only(['name', 'price', 'is_active', 'starts_at', 'ends_at']);

        DB::transaction(function () use ($request, $combo) {
            $combo->update($request->safe()->except('items'));
            if ($request->has('items')) {
                $combo->items()->delete();
                $combo->items()->createMany($request->validated('items'));
            }
        });

        $combo->refresh();
        $this->recordAudit($request, 'combo.updated', $combo, ['before' => $before, 'after' => $combo->only(array_keys($before))]);

        return response()->json(['data' => $this->serialize($combo->load('items.foodItem'))]);
    }

    public function destroy(Request $request, Combo $combo)
    {
        // Past orders keep their expanded lines and combo_name snapshot.
        $combo->delete();
        $this->recordAudit($request, 'combo.deleted', $combo);

        return response()->noContent();
    }

    private function serialize(Combo $combo): array
    {
        $regularMinor = $combo->items->sum(fn ($i) => Money::toMinor($i->foodItem?->base_price) * $i->quantity);

        return [
            'id' => $combo->id,
            'branchId' => $combo->branch_id,
            'name' => $combo->name,
            'description' => $combo->description,
            'price' => (float) $combo->price,
            'regularPrice' => Money::toFloat($regularMinor),
            'startsAt' => $combo->starts_at,
            'endsAt' => $combo->ends_at,
            'isActive' => $combo->is_active,
            'isOrderableNow' => $combo->isOrderableNow(),
            'items' => $combo->items->map(fn ($i) => [
                'foodItemId' => $i->food_item_id,
                'name' => $i->foodItem?->name,
                'quantity' => $i->quantity,
            ])->values(),
        ];
    }
}
