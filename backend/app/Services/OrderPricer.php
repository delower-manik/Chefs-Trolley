<?php

namespace App\Services;

use App\Exceptions\OrderRuleException;
use App\Models\Combo;
use App\Models\FoodItem;
use App\Models\Modifier;
use App\Models\TaxRate;
use App\Support\Money;

/**
 * The single place that turns "what the client asked for" into prices.
 * Clients send ids and quantities only; every amount is derived here from the
 * database (never trust client-supplied money). Used by order creation and by
 * adding a round to an open table tab, so both price identically.
 *
 * Tax resolution per food item, first match wins:
 *   1. the item's configured TaxRate (if active)
 *   2. the item's legacy flat `tax_rate` percentage when > 0 (exclusive)
 *   3. the branch's default active TaxRate
 *   4. no tax
 * Tax is computed on the pre-discount line total.
 */
class OrderPricer
{
    /**
     * @param  array<int, array<string, mixed>>  $items  each has either food_item_id or combo_id, plus quantity
     *
     * @throws OrderRuleException
     */
    public function price(string $branchId, array $items): PricedOrder
    {
        $defaultTax = TaxRate::where('branch_id', $branchId)->where('is_default', true)->where('is_active', true)->first();

        $lines = [];
        $subtotal = 0;
        $tax = 0;
        $taxInclusive = 0;
        $consumption = [];

        $addConsumption = function (FoodItem $item, int $qty) use (&$consumption) {
            foreach ($item->recipeIngredients as $recipeLine) {
                $consumption[$recipeLine->ingredient_id] = ($consumption[$recipeLine->ingredient_id] ?? 0)
                    + (float) $recipeLine->quantity_required * $qty;
            }
        };

        foreach ($items as $line) {
            $hasFood = ! empty($line['food_item_id']);
            $hasCombo = ! empty($line['combo_id']);
            if ($hasFood === $hasCombo) {
                throw new OrderRuleException('invalid_line', 'Each line needs exactly one of food_item_id or combo_id.');
            }

            $quantity = (int) $line['quantity'];

            if ($hasCombo) {
                foreach ($this->priceCombo($branchId, $line['combo_id'], $quantity, $line['notes'] ?? null, $defaultTax) as $comp) {
                    $subtotal += $comp['total_minor'];
                    $tax += $comp['tax_minor'];
                    $taxInclusive += $comp['tax_inclusive_minor'];
                    $addConsumption($comp['food_item'], $comp['quantity']);
                    $lines[] = $comp['attributes'];
                }

                continue;
            }

            $foodItem = FoodItem::with(['modifierGroups.modifiers', 'recipeIngredients', 'kitchenStation', 'taxRateConfig'])
                ->findOrFail($line['food_item_id']);
            $this->assertOrderable($foodItem, $branchId);

            $selectedModifierIds = $line['modifier_ids'] ?? [];
            $validModifierIds = $foodItem->modifierGroups->pluck('modifiers')->flatten()->pluck('id')->all();
            if (array_diff($selectedModifierIds, $validModifierIds) !== []) {
                throw new OrderRuleException('invalid_modifier', "One or more selected modifiers don't belong to \"{$foodItem->name}\".");
            }

            $modifiers = Modifier::whereIn('id', $selectedModifierIds)->get();
            $unitMinor = Money::toMinor($foodItem->base_price)
                + $modifiers->sum(fn ($m) => Money::toMinor($m->price));
            $totalMinor = $unitMinor * $quantity;

            [$lineTax, $lineTaxIncl] = $this->taxFor($foodItem, $defaultTax, $totalMinor);

            $subtotal += $totalMinor;
            $tax += $lineTax;
            $taxInclusive += $lineTaxIncl;
            $addConsumption($foodItem, $quantity);

            $lines[] = [
                'food_item_id' => $foodItem->id,
                'kitchen_station_id' => $this->stationId($foodItem, $branchId),
                'name' => $foodItem->name,
                'quantity' => $quantity,
                'unit_price' => Money::toDecimal($unitMinor),
                'total' => Money::toDecimal($totalMinor),
                'modifiers' => $modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price' => (float) $m->price])->all(),
                'notes' => $line['notes'] ?? null,
            ];
        }

        return new PricedOrder($lines, $subtotal, $tax, $taxInclusive, $consumption);
    }

    /**
     * Expands a combo into component lines. The combo price (x quantity) is
     * spread across components in proportion to their regular prices, with the
     * paisa remainder allocated so the lines add up to exactly the combo price.
     *
     * @return array<int, array<string, mixed>>
     */
    private function priceCombo(string $branchId, string $comboId, int $comboQty, ?string $notes, ?TaxRate $defaultTax): array
    {
        $combo = Combo::with(['items.foodItem.recipeIngredients', 'items.foodItem.kitchenStation', 'items.foodItem.taxRateConfig'])
            ->findOrFail($comboId);

        if ($combo->branch_id !== $branchId) {
            throw new OrderRuleException('combo_not_in_branch', "\"{$combo->name}\" is not available at this branch.");
        }
        if (! $combo->isOrderableNow()) {
            throw new OrderRuleException('combo_unavailable', "\"{$combo->name}\" is not available right now.");
        }
        if ($combo->items->isEmpty()) {
            throw new OrderRuleException('combo_empty', "\"{$combo->name}\" has no items configured.");
        }

        $weights = [];
        foreach ($combo->items as $i => $ci) {
            $this->assertOrderable($ci->foodItem, $branchId);
            $weights[$i] = max(0, Money::toMinor($ci->foodItem->base_price) * $ci->quantity);
        }

        $allocated = Money::allocate(Money::toMinor($combo->price) * $comboQty, $weights);

        $out = [];
        foreach ($combo->items as $i => $ci) {
            $item = $ci->foodItem;
            $qty = $ci->quantity * $comboQty;
            $totalMinor = $allocated[$i];
            [$lineTax, $lineTaxIncl] = $this->taxFor($item, $defaultTax, $totalMinor);

            $out[] = [
                'food_item' => $item,
                'quantity' => $qty,
                'total_minor' => $totalMinor,
                'tax_minor' => $lineTax,
                'tax_inclusive_minor' => $lineTaxIncl,
                'attributes' => [
                    'food_item_id' => $item->id,
                    'kitchen_station_id' => $this->stationId($item, $branchId),
                    'combo_id' => $combo->id,
                    'combo_name' => $combo->name,
                    'name' => $item->name,
                    'quantity' => $qty,
                    'unit_price' => Money::toDecimal(intdiv($totalMinor * 2 + $qty, $qty * 2)),
                    'total' => Money::toDecimal($totalMinor),
                    'modifiers' => [],
                    'notes' => $notes,
                ],
            ];
        }

        return $out;
    }

    private function assertOrderable(FoodItem $item, string $branchId): void
    {
        if ($item->branch_id !== null && $item->branch_id !== $branchId) {
            throw new OrderRuleException('item_not_in_branch', "\"{$item->name}\" is not available at this branch.");
        }
        if (! $item->is_available) {
            throw new OrderRuleException('item_unavailable', "\"{$item->name}\" is currently marked unavailable.");
        }
    }

    /** @return array{0:int,1:int} [tax, portion of it that is inclusive] */
    private function taxFor(FoodItem $item, ?TaxRate $defaultTax, int $lineTotalMinor): array
    {
        $configured = $item->taxRateConfig;
        if ($configured && $configured->is_active) {
            return $this->applyRate($configured, $lineTotalMinor);
        }
        if ((float) $item->tax_rate > 0) {
            return [Money::percent($lineTotalMinor, $item->tax_rate), 0];
        }
        if ($defaultTax) {
            return $this->applyRate($defaultTax, $lineTotalMinor);
        }

        return [0, 0];
    }

    /** @return array{0:int,1:int} */
    private function applyRate(TaxRate $rate, int $lineTotalMinor): array
    {
        if ($rate->is_inclusive) {
            $t = Money::inclusiveTax($lineTotalMinor, $rate->rate);

            return [$t, $t];
        }

        return [Money::percent($lineTotalMinor, $rate->rate), 0];
    }

    /** Snapshot the station only if it is still active and belongs to this branch. */
    private function stationId(FoodItem $item, string $branchId): ?string
    {
        $station = $item->kitchenStation;

        return $station && $station->is_active && $station->branch_id === $branchId ? $station->id : null;
    }
}
