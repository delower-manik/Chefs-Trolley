<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FoodItem;
use App\Models\Ingredient;
use App\Models\RecipeIngredient;
use App\Models\StockLedgerEntry;
use App\Models\Unit;
use Illuminate\Support\Str;

/** Small fixtures for tests that need menu items, recipes and stock. */
trait BuildsMenu
{
    protected function makeFoodItem(?string $branchId, float|int|string $price, array $attrs = []): FoodItem
    {
        $category = Category::firstOrCreate(
            ['slug' => 'test-cat', 'branch_id' => $branchId],
            ['name' => 'Test category'],
        );

        $name = $attrs['name'] ?? 'Dish '.Str::random(6);

        return FoodItem::create(array_merge([
            'category_id' => $category->id,
            'branch_id' => $branchId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'base_price' => $price,
        ], $attrs));
    }

    /** Ingredient with $stock on hand in the branch's default warehouse, and a recipe line on $item. */
    protected function giveRecipe(FoodItem $item, string $branchId, float $perDish, float $stock): Ingredient
    {
        $unit = Unit::create(['name' => 'Unit '.Str::random(4), 'abbreviation' => 'u-'.Str::random(5)]);
        $ingredient = Ingredient::create(['branch_id' => $branchId, 'unit_id' => $unit->id, 'name' => 'Ing '.Str::random(5)]);
        RecipeIngredient::create([
            'food_item_id' => $item->id, 'ingredient_id' => $ingredient->id, 'quantity_required' => $perDish,
        ]);
        StockLedgerEntry::record($ingredient, Branch::findOrFail($branchId)->defaultWarehouse(), 'correction', $stock);

        return $ingredient;
    }
}
