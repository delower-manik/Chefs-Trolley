<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;

/** Debits recipe ingredients for an order. Shared by the staff POS and the customer website so both stop at zero stock. */
class OrderStock
{
    /**
     * allowNegative: false is a hard stop: if this would take any ingredient below zero, StockLedgerEntry::record()
     * throws from inside its row lock, rolling back the caller's transaction (race-safe for the last portion).
     *
     * @param  array<string, float|int>  $recipeConsumption  ingredient id => quantity needed
     *
     * @throws InsufficientStockException
     */
    public function consume(Order $order, array $recipeConsumption, Warehouse $warehouse, ?string $actorId): void
    {
        // Always lock ingredient rows in the same order so two orders can never deadlock each other.
        ksort($recipeConsumption);
        foreach ($recipeConsumption as $ingredientId => $quantityNeeded) {
            $ingredient = Ingredient::find($ingredientId);
            if (! $ingredient) {
                continue; // ingredient was deleted after the recipe was saved
            }
            StockLedgerEntry::record(
                ingredient: $ingredient,
                warehouse: $warehouse,
                type: 'sale_consumption',
                quantityChange: -$quantityNeeded,
                reason: "Consumed by order {$order->code}",
                referenceType: Order::class,
                referenceId: $order->id,
                actorId: $actorId,
                allowNegative: false,
            );
        }
    }
}
