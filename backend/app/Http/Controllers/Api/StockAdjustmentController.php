<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    /**
     * GET /stock/ledger — a branch-scoped feed of every stock movement,
     * newest first. This is the audit trail a manager reads to answer
     * "why does this ingredient show 4kg instead of 10kg."
     */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = StockLedgerEntry::query()->with(['ingredient', 'warehouse'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('ingredient_id'), fn ($q) => $q->where('ingredient_id', $request->query('ingredient_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->query('warehouse_id')))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 30), 100);
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

    /**
     * POST /stock/adjustments
     * Manual correction ("adjustment", either direction) or recorded loss
     * ("waste", always negative and always reasoned) at a specific
     * warehouse — architecture §10 / §5 "Stock Adjustments" and
     * "Waste/Spoilage".
     */
    public function store(StockAdjustmentRequest $request)
    {
        $ingredient = Ingredient::findOrFail($request->validated('ingredient_id'));
        $warehouse = Warehouse::findOrFail($request->validated('warehouse_id'));
        $type = $request->validated('type');
        $quantityChange = (float) $request->validated('quantity_change');

        if ($type === 'waste' && $quantityChange > 0) {
            return response()->json([
                'code' => 'invalid_waste_direction',
                'message' => 'Waste can only reduce stock. Use "adjustment" to record found/extra stock.',
            ], 422);
        }

        $entry = StockLedgerEntry::record(
            ingredient: $ingredient,
            warehouse: $warehouse,
            type: $type,
            quantityChange: $quantityChange,
            reason: $request->validated('reason'),
            actorId: $request->user()->id,
        );

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $type === 'waste' ? 'stock.waste_recorded' : 'stock.adjusted',
            subjectType: Ingredient::class,
            subjectId: $ingredient->id,
            branchId: $ingredient->branch_id,
            changes: ['quantityChange' => $quantityChange, 'balanceAfter' => (float) $entry->balance_after, 'reason' => $entry->reason],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($entry->load(['ingredient', 'warehouse']))], 201);
    }

    private function serialize(StockLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'ingredientId' => $entry->ingredient_id,
            'ingredientName' => $entry->ingredient?->name,
            'warehouseId' => $entry->warehouse_id,
            'warehouseName' => $entry->warehouse?->name,
            'type' => $entry->type,
            'quantityChange' => (float) $entry->quantity_change,
            'balanceAfter' => (float) $entry->balance_after,
            'reason' => $entry->reason,
            'referenceType' => $entry->reference_type,
            'createdAt' => $entry->created_at,
        ];
    }
}
