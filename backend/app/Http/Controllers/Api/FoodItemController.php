<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveFoodItemRequest;
use App\Models\AuditLog;
use App\Models\FoodItem;
use App\Support\Like;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FoodItemController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = FoodItem::query()->with(['category', 'modifierGroups.modifiers'])
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->when($request->filled('search'), fn ($q) => $q->whereRaw(Like::clause('name'), [Like::contains((string) $request->query('search'))]))
            ->orderBy('sort_order')
            ->orderBy('name');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map($this->serialize(...)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(FoodItem $foodItem)
    {
        $foodItem->load(['category', 'modifierGroups.modifiers']);

        return response()->json(['data' => $this->serialize($foodItem)]);
    }

    public function store(SaveFoodItemRequest $request)
    {
        $foodItem = DB::transaction(function () use ($request) {
            $item = FoodItem::create([
                ...collect($request->validated())->except('modifier_groups')->all(),
                'slug' => $request->slug(),
            ]);

            $this->syncModifierGroups($item, $request->validated('modifier_groups', []));

            return $item;
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'food_item.created',
            subjectType: FoodItem::class,
            subjectId: $foodItem->id,
            branchId: $foodItem->branch_id,
            changes: ['after' => $foodItem->only(['name', 'base_price', 'is_available'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($foodItem->load('category', 'modifierGroups.modifiers'))], 201);
    }

    public function update(SaveFoodItemRequest $request, FoodItem $foodItem)
    {
        $before = $foodItem->only(['name', 'base_price', 'is_available']);

        DB::transaction(function () use ($request, $foodItem) {
            $foodItem->update([
                ...collect($request->validated())->except('modifier_groups')->all(),
                'slug' => $request->slug(),
            ]);

            if ($request->has('modifier_groups')) {
                $foodItem->modifierGroups()->delete(); // cascades to modifiers
                $this->syncModifierGroups($foodItem, $request->validated('modifier_groups', []));
            }
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'food_item.updated',
            subjectType: FoodItem::class,
            subjectId: $foodItem->id,
            branchId: $foodItem->branch_id,
            changes: ['before' => $before, 'after' => $foodItem->fresh()->only(['name', 'base_price', 'is_available'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($foodItem->fresh(['category', 'modifierGroups.modifiers']))]);
    }

    public function destroy(Request $request, FoodItem $foodItem)
    {
        $foodItem->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'food_item.deleted',
            subjectType: FoodItem::class,
            subjectId: $foodItem->id,
            branchId: $foodItem->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function syncModifierGroups(FoodItem $item, array $groups): void
    {
        foreach ($groups as $index => $group) {
            $modifierGroup = $item->modifierGroups()->create([
                'name' => $group['name'],
                'is_required' => $group['is_required'] ?? false,
                'min_select' => $group['min_select'] ?? 0,
                'max_select' => $group['max_select'] ?? 1,
                'sort_order' => $index,
            ]);

            foreach ($group['modifiers'] ?? [] as $mIndex => $modifier) {
                $modifierGroup->modifiers()->create([
                    'name' => $modifier['name'],
                    'price' => $modifier['price'],
                    'sort_order' => $mIndex,
                ]);
            }
        }
    }

    private function serialize(FoodItem $item): array
    {
        return [
            'id' => $item->id,
            'categoryId' => $item->category_id,
            'categoryName' => $item->category?->name,
            'branchId' => $item->branch_id,
            'name' => $item->name,
            'slug' => $item->slug,
            'description' => $item->description,
            'basePrice' => (float) $item->base_price,
            'taxRate' => (float) $item->tax_rate,
            'taxRateId' => $item->tax_rate_id,
            'kitchenStationId' => $item->kitchen_station_id,
            'imagePath' => $item->image_path,
            'isAvailable' => $item->is_available,
            'sortOrder' => $item->sort_order,
            'modifierGroups' => $item->modifierGroups->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'isRequired' => $g->is_required,
                'minSelect' => $g->min_select,
                'maxSelect' => $g->max_select,
                'modifiers' => $g->modifiers->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'price' => (float) $m->price,
                    'isActive' => $m->is_active,
                ]),
            ]),
        ];
    }
}
