<?php

namespace App\Services;

/**
 * Result of pricing a set of order lines. All money is integer paisa.
 *
 * @phpstan-type Line array<string, mixed>
 */
final class PricedOrder
{
    /**
     * @param  array<int, array<string, mixed>>  $lines  attributes ready for order_items (money as 2-dp strings)
     * @param  array<string, float>  $recipeConsumption  ingredient_id => quantity to deduct
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $subtotal,
        public readonly int $tax,
        public readonly int $taxInclusive,
        public readonly array $recipeConsumption,
    ) {}

    /** Tax that is added on top of the subtotal (inclusive tax is already in it). */
    public function taxExclusive(): int
    {
        return $this->tax - $this->taxInclusive;
    }
}
