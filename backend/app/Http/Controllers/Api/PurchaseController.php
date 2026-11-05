<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePurchaseRequest;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = Purchase::query()->with(['supplier', 'warehouse', 'items.ingredient'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('created_at');

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

    /**
     * POST /purchases
     * Records a purchase order as a draft -- stock does NOT land yet. A
     * separate `receive` step is what actually books the stock, matching
     * real purchasing practice (an order placed isn't stock in hand).
     */
    public function store(CreatePurchaseRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json([
                'code' => 'branch_required',
                'message' => 'Select an active branch before recording a purchase.',
            ], 422);
        }

        $items = $request->validated('items');
        $totalCost = collect($items)->sum(fn ($i) => $i['quantity'] * $i['unit_cost']);

        $purchase = DB::transaction(function () use ($request, $branchId, $items, $totalCost) {
            $purchase = Purchase::create([
                'branch_id' => $branchId,
                'supplier_id' => $request->validated('supplier_id'),
                'warehouse_id' => $request->validated('warehouse_id'),
                'status' => 'draft',
                'total_cost' => $totalCost,
                'created_by' => $request->user()->id,
            ]);

            foreach ($items as $line) {
                $purchase->items()->create([
                    'ingredient_id' => $line['ingredient_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'total_cost' => round($line['quantity'] * $line['unit_cost'], 2),
                ]);
            }

            return $purchase;
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'purchase.created',
            subjectType: Purchase::class,
            subjectId: $purchase->id,
            branchId: $purchase->branch_id,
            changes: ['after' => $purchase->only(['supplier_id', 'total_cost', 'status'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($purchase->load(['supplier', 'warehouse', 'items.ingredient']))], 201);
    }

    /**
     * POST /purchases/{purchase}/receive
     * The stock-landing step: one `purchase` ledger entry per line, into
     * the purchase's chosen warehouse, inside a single transaction.
     */
    public function receive(Request $request, Purchase $purchase)
    {
        if (! $purchase->isReceivable()) {
            return response()->json([
                'code' => 'invalid_purchase_state',
                'message' => "This purchase is \"{$purchase->status}\" and can no longer be received.",
            ], 422);
        }

        DB::transaction(function () use ($request, $purchase) {
            $warehouse = Warehouse::findOrFail($purchase->warehouse_id);

            foreach ($purchase->items as $line) {
                $ingredient = Ingredient::findOrFail($line->ingredient_id);

                StockLedgerEntry::record(
                    ingredient: $ingredient,
                    warehouse: $warehouse,
                    type: 'purchase',
                    quantityChange: (float) $line->quantity,
                    reason: "Purchase from supplier (PO {$purchase->id})",
                    referenceType: Purchase::class,
                    referenceId: $purchase->id,
                    actorId: $request->user()->id,
                );
            }

            $purchase->update(['status' => 'received', 'received_at' => now()]);
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'purchase.received',
            subjectType: Purchase::class,
            subjectId: $purchase->id,
            branchId: $purchase->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($purchase->fresh(['supplier', 'warehouse', 'items.ingredient']))]);
    }

    /**
     * POST /purchases/{purchase}/cancel
     * Only a draft (never-received) purchase can be cancelled this way --
     * unwinding a purchase that already landed stock is a return/reversal
     * flow this pass doesn't build (see README).
     */
    public function cancel(Request $request, Purchase $purchase)
    {
        if (! $purchase->isCancellable()) {
            return response()->json([
                'code' => 'invalid_purchase_state',
                'message' => "This purchase is \"{$purchase->status}\" and can't be cancelled here.",
            ], 422);
        }

        $purchase->update(['status' => 'cancelled']);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'purchase.cancelled',
            subjectType: Purchase::class,
            subjectId: $purchase->id,
            branchId: $purchase->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($purchase->fresh(['supplier', 'warehouse', 'items.ingredient']))]);
    }

    private function serialize(Purchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'branchId' => $purchase->branch_id,
            'supplierId' => $purchase->supplier_id,
            'supplierName' => $purchase->supplier?->name,
            'warehouseId' => $purchase->warehouse_id,
            'warehouseName' => $purchase->warehouse?->name,
            'status' => $purchase->status,
            'totalCost' => (float) $purchase->total_cost,
            'receivedAt' => $purchase->received_at,
            'items' => $purchase->items->map(fn ($item) => [
                'id' => $item->id,
                'ingredientId' => $item->ingredient_id,
                'ingredientName' => $item->ingredient?->name,
                'quantity' => (float) $item->quantity,
                'unitCost' => (float) $item->unit_cost,
                'totalCost' => (float) $item->total_cost,
            ]),
            'createdAt' => $purchase->created_at,
        ];
    }
}
