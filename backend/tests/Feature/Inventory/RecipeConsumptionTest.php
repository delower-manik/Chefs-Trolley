<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\FoodItem;
use App\Models\Ingredient;
use App\Models\RecipeIngredient;
use App\Models\StockLedgerEntry;
use App\Models\Unit;
use Tests\TestCase;

class RecipeConsumptionTest extends TestCase
{
    public function test_placing_an_order_consumes_the_recipes_ingredients(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'menu.manage', 'inventory.manage']);
        $branch = $user->activeBranch;
        $warehouse = $branch->defaultWarehouse();

        $unit = Unit::create(['name' => 'Gram', 'abbreviation' => 'g-'.uniqid()]);
        $rice = Ingredient::create(['branch_id' => $branch->id, 'unit_id' => $unit->id, 'name' => 'Rice']);
        $chicken = Ingredient::create(['branch_id' => $branch->id, 'unit_id' => $unit->id, 'name' => 'Chicken']);
        StockLedgerEntry::record($rice, $warehouse, 'correction', 1000);
        StockLedgerEntry::record($chicken, $warehouse, 'correction', 500);

        $category = Category::create(['name' => 'Biryani', 'slug' => 'biryani-'.uniqid()]);
        $biryani = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Chicken Biryani', 'slug' => 'biryani-'.uniqid(), 'base_price' => 350,
        ]);
        RecipeIngredient::create(['food_item_id' => $biryani->id, 'ingredient_id' => $rice->id, 'quantity_required' => 200]);
        RecipeIngredient::create(['food_item_id' => $biryani->id, 'ingredient_id' => $chicken->id, 'quantity_required' => 150]);

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [['food_item_id' => $biryani->id, 'quantity' => 2]],
        ]);

        $response->assertStatus(201);

        // 2 orders of biryani -> 400g rice, 300g chicken consumed
        $this->assertEquals(600, $rice->totalStock());
        $this->assertEquals(200, $chicken->totalStock());

        $ledger = $this->getJson('/api/v1/stock/ledger');
        $ledger->assertJsonFragment(['type' => 'sale_consumption', 'quantityChange' => -400])
            ->assertJsonFragment(['type' => 'sale_consumption', 'quantityChange' => -300]);
    }

    public function test_a_held_draft_order_does_not_consume_stock_yet(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $branch = $user->activeBranch;
        $warehouse = $branch->defaultWarehouse();

        $unit = Unit::create(['name' => 'Gram', 'abbreviation' => 'g-'.uniqid()]);
        $flour = Ingredient::create(['branch_id' => $branch->id, 'unit_id' => $unit->id, 'name' => 'Flour']);
        StockLedgerEntry::record($flour, $warehouse, 'correction', 100);

        $category = Category::create(['name' => 'Naan', 'slug' => 'naan-'.uniqid()]);
        $naan = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Naan', 'slug' => 'naan-'.uniqid(), 'base_price' => 40,
        ]);
        RecipeIngredient::create(['food_item_id' => $naan->id, 'ingredient_id' => $flour->id, 'quantity_required' => 50]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [['food_item_id' => $naan->id, 'quantity' => 1]],
            'hold' => true,
        ])->assertStatus(201)->assertJsonPath('data.status', 'draft');

        $this->assertEquals(100, $flour->totalStock());
    }

    public function test_selling_a_food_item_with_no_recipe_defined_does_not_error(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);

        $category = Category::create(['name' => 'Drinks', 'slug' => 'drinks-'.uniqid()]);
        $cola = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Cola', 'slug' => 'cola-'.uniqid(), 'base_price' => 60,
        ]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [['food_item_id' => $cola->id, 'quantity' => 1]],
        ])->assertStatus(201);
    }

    public function test_an_order_that_would_oversell_an_ingredient_is_rejected_and_nothing_is_created(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $branch = $user->activeBranch;
        $warehouse = $branch->defaultWarehouse();

        $unit = Unit::create(['name' => 'Gram', 'abbreviation' => 'g-'.uniqid()]);
        $cheese = Ingredient::create(['branch_id' => $branch->id, 'unit_id' => $unit->id, 'name' => 'Mozzarella']);
        StockLedgerEntry::record($cheese, $warehouse, 'correction', 100); // only 100g on hand

        $category = Category::create(['name' => 'Pizza', 'slug' => 'pizza-'.uniqid()]);
        $pizza = FoodItem::create([
            'category_id' => $category->id, 'name' => 'Cheese Pizza', 'slug' => 'pizza-'.uniqid(), 'base_price' => 500,
        ]);
        // Needs 150g per pizza -- ordering 1 exceeds the 100g on hand.
        RecipeIngredient::create(['food_item_id' => $pizza->id, 'ingredient_id' => $cheese->id, 'quantity_required' => 150]);

        $response = $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [['food_item_id' => $pizza->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

        // Nothing was created and nothing was debited -- checkout failed cleanly.
        $this->assertEquals(100, $cheese->totalStock());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_recipe_shared_across_two_line_items_is_only_checked_against_their_combined_need(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view']);
        $branch = $user->activeBranch;
        $warehouse = $branch->defaultWarehouse();

        $unit = Unit::create(['name' => 'Gram', 'abbreviation' => 'g-'.uniqid()]);
        $tomatoSauce = Ingredient::create(['branch_id' => $branch->id, 'unit_id' => $unit->id, 'name' => 'Tomato Sauce']);
        StockLedgerEntry::record($tomatoSauce, $warehouse, 'correction', 250);

        $category = Category::create(['name' => 'Italian', 'slug' => 'italian-'.uniqid()]);
        $pasta = FoodItem::create(['category_id' => $category->id, 'name' => 'Pasta', 'slug' => 'pasta-'.uniqid(), 'base_price' => 300]);
        $pizza = FoodItem::create(['category_id' => $category->id, 'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => 450]);
        RecipeIngredient::create(['food_item_id' => $pasta->id, 'ingredient_id' => $tomatoSauce->id, 'quantity_required' => 80]);
        RecipeIngredient::create(['food_item_id' => $pizza->id, 'ingredient_id' => $tomatoSauce->id, 'quantity_required' => 100]);

        // 80 + 100 = 180, within the 250g available.
        $this->postJson('/api/v1/orders', [
            'source' => 'pos',
            'items' => [
                ['food_item_id' => $pasta->id, 'quantity' => 1],
                ['food_item_id' => $pizza->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);

        $this->assertEquals(70, $tomatoSauce->totalStock());
    }
}
