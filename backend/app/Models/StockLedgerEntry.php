<?php

namespace App\Models;

use App\Exceptions\InsufficientStockException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class StockLedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false; // only created_at is tracked, set explicitly

    protected $fillable = [
        'ingredient_id', 'warehouse_id', 'branch_id', 'type', 'quantity_change', 'balance_after',
        'reason', 'reference_type', 'reference_id', 'actor_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The single write path for every stock movement anywhere in the
     * system (Skill: Inventory Engineer -- "all adjustments need reason,
     * actor, timestamp and source"). Locks the specific ingredient+
     * warehouse balance row for update so two movements landing at the
     * same instant (a purchase arriving while a sale consumes stock)
     * can't race and leave the balance inconsistent with the ledger.
     *
     * When $allowNegative is false, the resulting balance is checked
     * *inside* this same lock before anything is written -- this is what
     * makes the check race-safe: a second concurrent caller blocks on the
     * row lock until the first transaction commits, then reads the
     * already-decremented balance, rather than both readers seeing the
     * same stale "still enough" snapshot.
     *
     * @throws InsufficientStockException
     */
    public static function record(
        Ingredient $ingredient,
        Warehouse $warehouse,
        string $type,
        float $quantityChange,
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $actorId = null,
        bool $allowNegative = true,
    ): self {
        return DB::transaction(function () use (
            $ingredient, $warehouse, $type, $quantityChange, $reason, $referenceType, $referenceId, $actorId, $allowNegative
        ) {
            // Ensure the balance row exists, then re-select it under a lock --
            // firstOrCreate itself isn't safe to lock atomically.
            IngredientStock::firstOrCreate(
                ['ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id],
                ['quantity' => 0]
            );

            $stockRow = IngredientStock::where('ingredient_id', $ingredient->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            $newBalance = (float) $stockRow->quantity + $quantityChange;

            if (! $allowNegative && $newBalance < 0) {
                throw new InsufficientStockException(
                    ingredient: $ingredient,
                    available: (float) $stockRow->quantity,
                    requested: abs($quantityChange),
                );
            }

            $stockRow->update(['quantity' => $newBalance]);

            return self::create([
                'ingredient_id' => $ingredient->id,
                'warehouse_id' => $warehouse->id,
                'branch_id' => $ingredient->branch_id,
                'type' => $type,
                'quantity_change' => $quantityChange,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actorId,
                'created_at' => now(),
            ]);
        });
    }
}
