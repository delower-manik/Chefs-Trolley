<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveIngredientRequest;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Support\Like;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = Ingredient::query()
            ->with('unit')
            ->withSum('stocks as total_stock', 'quantity')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('search'), fn ($q) => $q->whereRaw(Like::clause('name'), [Like::contains((string) $request->query('search'))]))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
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

    public function store(SaveIngredientRequest $request)
    {
        $ingredient = Ingredient::create($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'ingredient.created',
            subjectType: Ingredient::class,
            subjectId: $ingredient->id,
            branchId: $ingredient->branch_id,
            changes: ['after' => $ingredient->only(['name', 'reorder_level'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($ingredient->load('unit'))], 201);
    }

    public function update(SaveIngredientRequest $request, Ingredient $ingredient)
    {
        $before = $ingredient->only(['name', 'reorder_level', 'is_active']);
        $ingredient->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'ingredient.updated',
            subjectType: Ingredient::class,
            subjectId: $ingredient->id,
            branchId: $ingredient->branch_id,
            changes: ['before' => $before, 'after' => $ingredient->fresh()->only(['name', 'reorder_level', 'is_active'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($ingredient->fresh('unit'))]);
    }

    public function destroy(Request $request, Ingredient $ingredient)
    {
        $ingredient->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'ingredient.deleted',
            subjectType: Ingredient::class,
            subjectId: $ingredient->id,
            branchId: $ingredient->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Ingredient $ingredient): array
    {
        return [
            'id' => $ingredient->id,
            'branchId' => $ingredient->branch_id,
            'name' => $ingredient->name,
            'unitId' => $ingredient->unit_id,
            'unitAbbreviation' => $ingredient->unit?->abbreviation,
            'currentStock' => $ingredient->totalStock(),
            'reorderLevel' => (float) $ingredient->reorder_level,
            'costPerUnit' => (float) $ingredient->cost_per_unit,
            'isLowStock' => $ingredient->isLowStock(),
            'isActive' => $ingredient->is_active,
        ];
    }
}
