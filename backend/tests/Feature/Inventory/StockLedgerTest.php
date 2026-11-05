<?php

namespace Tests\Feature\Inventory;

use App\Models\Ingredient;
use App\Models\StockLedgerEntry;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    private function makeIngredient(): Ingredient
    {
        $unit = Unit::create(['name' => 'Kilogram', 'abbreviation' => 'kg-'.uniqid()]);
        $branch = $this->createBranch();

        return Ingredient::create([
            'branch_id' => $branch->id,
            'unit_id' => $unit->id,
            'name' => 'Rice',
            'reorder_level' => 5,
        ]);
    }

    public function test_a_new_branch_gets_a_default_warehouse_automatically(): void
    {
        $ingredient = $this->makeIngredient();
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);

        $response = $this->getJson('/api/v1/warehouses');

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.isDefault', true);
    }

    public function test_recording_a_purchase_only_lands_stock_after_its_received(): void
    {
        $ingredient = $this->makeIngredient();
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $supplier = Supplier::create(['name' => 'Local Farm']);
        $warehouse = $ingredient->branch->defaultWarehouse();

        $create = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 10, 'unit_cost' => 80]],
        ]);

        $create->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $this->assertEquals(0, $ingredient->totalStock(), 'stock should not land until received');

        $purchaseId = $create->json('data.id');
        $receive = $this->postJson("/api/v1/purchases/{$purchaseId}/receive");

        $receive->assertStatus(200)->assertJsonPath('data.status', 'received');
        $this->assertEquals(10, $ingredient->fresh()->totalStock());

        $ledger = $this->getJson('/api/v1/stock/ledger');
        $ledger->assertJsonPath('data.0.type', 'purchase')->assertJsonPath('data.0.balanceAfter', 10);
    }

    public function test_a_draft_purchase_can_be_cancelled_without_touching_stock(): void
    {
        $ingredient = $this->makeIngredient();
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $supplier = Supplier::create(['name' => 'Local Farm']);
        $warehouse = $ingredient->branch->defaultWarehouse();

        $purchaseId = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 10, 'unit_cost' => 80]],
        ])->json('data.id');

        $this->postJson("/api/v1/purchases/{$purchaseId}/cancel")
            ->assertStatus(200)->assertJsonPath('data.status', 'cancelled');

        // Cancelling an already-cancelled purchase is rejected.
        $this->postJson("/api/v1/purchases/{$purchaseId}/receive")->assertStatus(422);
        $this->assertEquals(0, $ingredient->totalStock());
    }

    private function landStock(Ingredient $ingredient, Warehouse $warehouse, float $quantity): void
    {
        StockLedgerEntry::record(
            ingredient: $ingredient, warehouse: $warehouse, type: 'correction', quantityChange: $quantity,
        );
    }

    public function test_waste_always_reduces_stock_and_requires_a_reason(): void
    {
        $ingredient = $this->makeIngredient();
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $warehouse = $ingredient->branch->defaultWarehouse();
        $this->landStock($ingredient, $warehouse, 20);

        $this->postJson('/api/v1/stock/adjustments', [
            'ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id,
            'quantity_change' => -3, 'type' => 'waste',
        ])->assertStatus(422);

        $response = $this->postJson('/api/v1/stock/adjustments', [
            'ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id,
            'quantity_change' => -3, 'type' => 'waste', 'reason' => 'Spoiled in fridge',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.balanceAfter', 17);
        $this->assertEquals(17, $ingredient->fresh()->totalStock());
    }

    public function test_waste_cannot_be_recorded_as_a_positive_quantity(): void
    {
        $ingredient = $this->makeIngredient();
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $warehouse = $ingredient->branch->defaultWarehouse();
        $this->landStock($ingredient, $warehouse, 20);

        $this->postJson('/api/v1/stock/adjustments', [
            'ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id,
            'quantity_change' => 3, 'type' => 'waste', 'reason' => 'Found extra',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_waste_direction');
    }

    public function test_a_manual_adjustment_can_move_stock_either_direction_without_a_reason(): void
    {
        $ingredient = $this->makeIngredient();
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $warehouse = $ingredient->branch->defaultWarehouse();
        $this->landStock($ingredient, $warehouse, 10);

        $this->postJson('/api/v1/stock/adjustments', [
            'ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id,
            'quantity_change' => 5, 'type' => 'adjustment',
        ])->assertStatus(201)->assertJsonPath('data.balanceAfter', 15);

        $this->assertEquals(15, $ingredient->fresh()->totalStock());
    }

    public function test_ingredients_below_reorder_level_are_flagged_low_stock(): void
    {
        $ingredient = $this->makeIngredient();
        $ingredient->update(['reorder_level' => 5]);
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $warehouse = $ingredient->branch->defaultWarehouse();
        $this->landStock($ingredient, $warehouse, 2);

        $response = $this->getJson('/api/v1/ingredients?low_stock=1');

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.isLowStock', true);
    }

    public function test_transferring_stock_moves_it_between_warehouses(): void
    {
        $ingredient = $this->makeIngredient();
        $user = $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $main = $ingredient->branch->defaultWarehouse();
        $this->landStock($ingredient, $main, 50);

        $secondWarehouse = $this->postJson('/api/v1/warehouses', ['name' => 'Cold Storage'])->json('data');

        $response = $this->postJson('/api/v1/stock/transfers', [
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $secondWarehouse['id'],
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 20]],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(30, $ingredient->stockAt($main->fresh()));
        $this->assertEquals(20, $ingredient->stockAt(Warehouse::find($secondWarehouse['id'])));
        $this->assertEquals(50, $ingredient->totalStock(), 'total across warehouses is unchanged by a transfer');
    }

    public function test_a_transfer_is_rejected_when_the_source_warehouse_lacks_stock(): void
    {
        $ingredient = $this->makeIngredient();
        $this->actingAsUserWithPermissions(['inventory.manage'], $ingredient->branch);
        $main = $ingredient->branch->defaultWarehouse();
        $this->landStock($ingredient, $main, 5);

        $secondWarehouse = $this->postJson('/api/v1/warehouses', ['name' => 'Cold Storage'])->json('data');

        $this->postJson('/api/v1/stock/transfers', [
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $secondWarehouse['id'],
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 20]],
        ])->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

        $this->assertEquals(5, $ingredient->fresh()->totalStock(), 'a rejected transfer must not partially apply');
    }
}
