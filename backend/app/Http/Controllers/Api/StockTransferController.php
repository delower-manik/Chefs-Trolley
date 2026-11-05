<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStockTransferRequest;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\StockLedgerEntry;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $transfers = StockTransfer::query()->with(['fromWarehouse', 'toWarehouse', 'items.ingredient'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $transfers->map($this->serialize(...))]);
    }

    /**
     * POST /stock/transfers
     * Moves stock between two warehouses in the same branch. Unlike POS
     * checkout (see OrderController), a transfer is not a time-critical
     * customer-facing flow, so it's safe -- and expected -- to reject it
     * outright if the source warehouse doesn't actually have enough stock,
     * rather than letting a warehouse go negative.
     */
    public function store(CreateStockTransferRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $fromWarehouse = Warehouse::findOrFail($request->validated('from_warehouse_id'));
        $toWarehouse = Warehouse::findOrFail($request->validated('to_warehouse_id'));

        if ($fromWarehouse->branch_id !== $toWarehouse->branch_id || ($branchId && $fromWarehouse->branch_id !== $branchId)) {
            return response()->json([
                'code' => 'cross_branch_transfer',
                'message' => 'Both warehouses must belong to the branch you are currently working in.',
            ], 422);
        }

        $items = $request->validated('items');

        // Check availability for every line before moving anything, so a
        // transfer either fully succeeds or doesn't touch stock at all.
        foreach ($items as $line) {
            $ingredient = Ingredient::findOrFail($line['ingredient_id']);
            $available = $ingredient->stockAt($fromWarehouse);
            if ($available < $line['quantity']) {
                return response()->json([
                    'code' => 'insufficient_stock',
                    'message' => "Not enough {$ingredient->name} in {$fromWarehouse->name}: {$available} available, {$line['quantity']} requested.",
                ], 422);
            }
        }

        $transfer = DB::transaction(function () use ($request, $branchId, $fromWarehouse, $toWarehouse, $items) {
            $transfer = StockTransfer::create([
                'branch_id' => $branchId ?? $fromWarehouse->branch_id,
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'created_by' => $request->user()->id,
            ]);

            foreach ($items as $line) {
                $ingredient = Ingredient::findOrFail($line['ingredient_id']);
                $quantity = (float) $line['quantity'];

                $transfer->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => $quantity]);

                StockLedgerEntry::record(
                    ingredient: $ingredient, warehouse: $fromWarehouse, type: 'transfer_out',
                    quantityChange: -$quantity, reason: "Transfer to {$toWarehouse->name}",
                    referenceType: StockTransfer::class, referenceId: $transfer->id, actorId: $request->user()->id,
                );

                StockLedgerEntry::record(
                    ingredient: $ingredient, warehouse: $toWarehouse, type: 'transfer_in',
                    quantityChange: $quantity, reason: "Transfer from {$fromWarehouse->name}",
                    referenceType: StockTransfer::class, referenceId: $transfer->id, actorId: $request->user()->id,
                );
            }

            return $transfer;
        });

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'stock.transferred',
            subjectType: StockTransfer::class,
            subjectId: $transfer->id,
            branchId: $transfer->branch_id,
            changes: ['from' => $fromWarehouse->name, 'to' => $toWarehouse->name, 'lineCount' => count($items)],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($transfer->load(['fromWarehouse', 'toWarehouse', 'items.ingredient']))], 201);
    }

    private function serialize(StockTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'branchId' => $transfer->branch_id,
            'fromWarehouseId' => $transfer->from_warehouse_id,
            'fromWarehouseName' => $transfer->fromWarehouse?->name,
            'toWarehouseId' => $transfer->to_warehouse_id,
            'toWarehouseName' => $transfer->toWarehouse?->name,
            'items' => $transfer->items->map(fn ($item) => [
                'id' => $item->id,
                'ingredientId' => $item->ingredient_id,
                'ingredientName' => $item->ingredient?->name,
                'quantity' => (float) $item->quantity,
            ]),
            'createdAt' => $transfer->created_at,
        ];
    }
}
