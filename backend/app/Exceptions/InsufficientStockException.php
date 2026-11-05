<?php

namespace App\Exceptions;

use App\Models\Ingredient;
use Exception;

/**
 * Thrown by StockLedgerEntry::record() when a movement would take a
 * warehouse's balance below zero and negative stock wasn't explicitly
 * allowed for that call. Thrown from inside the same locked transaction
 * that read the balance, so -- unlike a check performed before the
 * transaction starts -- this can't be defeated by a second concurrent
 * request reading the same "still enough stock" snapshot.
 */
class InsufficientStockException extends Exception
{
    public function __construct(
        public readonly Ingredient $ingredient,
        public readonly float $available,
        public readonly float $requested,
    ) {
        parent::__construct(
            "Not enough {$ingredient->name} in stock: {$available} available, {$requested} requested."
        );
    }
}
