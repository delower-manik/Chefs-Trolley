<?php

namespace Tests\Feature\Restaurant;

use App\Models\Combo;
use App\Models\Order;
use App\Models\TaxRate;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

class ComboTest extends TestCase
{
    use BuildsMenu;

    private array $perms = ['orders.create', 'orders.view', 'menu.manage'];

    private function combo(string $branchId, array $items, float $price, array $attrs = []): Combo
    {
        $combo = Combo::create(array_merge(['branch_id' => $branchId, 'name' => 'Combo '.uniqid(), 'price' => $price], $attrs));
        foreach ($items as [$food, $qty]) {
            $combo->items()->create(['food_item_id' => $food->id, 'quantity' => $qty]);
        }

        return $combo;
    }

    public function test_ordering_a_combo_expands_into_lines_that_sum_to_the_combo_price(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $burger = $this->makeFoodItem($b, 300, ['name' => 'Burger']);
        $fries = $this->makeFoodItem($b, 100, ['name' => 'Fries']);
        $cola = $this->makeFoodItem($b, 100, ['name' => 'Cola']);
        $combo = $this->combo($b, [[$burger, 1], [$fries, 1], [$cola, 1]], 400);

        $res = $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['combo_id' => $combo->id, 'quantity' => 2]]])
            ->assertStatus(201)->assertJsonPath('data.subtotal', 800)->assertJsonPath('data.grandTotal', 800);

        $lines = Order::find($res->json('data.id'))->items;
        $this->assertCount(3, $lines);
        $this->assertEquals(800, $lines->sum('total'));
        $this->assertSame([2, 2, 2], $lines->pluck('quantity')->all());
        $this->assertSame($combo->id, $lines->first()->combo_id);
        $this->assertEquals(480, $lines->firstWhere('name', 'Burger')->total, 'burger carries 300/500 of the combo price');
    }

    public function test_allocation_never_loses_a_paisa(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $a = $this->makeFoodItem($b, 10);
        $c = $this->makeFoodItem($b, 10);
        $d = $this->makeFoodItem($b, 10);
        $combo = $this->combo($b, [[$a, 1], [$c, 1], [$d, 1]], 10);

        $id = $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['combo_id' => $combo->id, 'quantity' => 1]]])
            ->assertStatus(201)->json('data.id');

        $this->assertEqualsWithDelta(10.0, (float) Order::find($id)->items->sum('total'), 0.0001);
    }

    public function test_component_tax_applies_to_the_allocated_amount(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $rate = TaxRate::create(['branch_id' => $b, 'name' => 'VAT', 'rate' => 10]);
        $x = $this->makeFoodItem($b, 100, ['tax_rate_id' => $rate->id]);
        $combo = $this->combo($b, [[$x, 1]], 80);

        $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['combo_id' => $combo->id, 'quantity' => 1]]])
            ->assertStatus(201)->assertJsonPath('data.taxTotal', 8)->assertJsonPath('data.grandTotal', 88);
    }

    public function test_combo_consumes_stock_of_each_component_recipe(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $x = $this->makeFoodItem($b, 100);
        $ing = $this->giveRecipe($x, $b, 5, 100);
        $combo = $this->combo($b, [[$x, 2]], 150);

        $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['combo_id' => $combo->id, 'quantity' => 3]]])->assertStatus(201);

        $this->assertEquals(100 - (5 * 2 * 3), $ing->totalStock());
    }

    public function test_out_of_window_inactive_or_foreign_combos_are_rejected(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $x = $this->makeFoodItem($b, 100);

        $expired = $this->combo($b, [[$x, 1]], 90, ['ends_at' => now()->subDay()]);
        $future = $this->combo($b, [[$x, 1]], 90, ['starts_at' => now()->addDay()]);
        $off = $this->combo($b, [[$x, 1]], 90, ['is_active' => false]);
        $foreign = $this->combo($this->createBranch()->id, [[$this->makeFoodItem(null, 50), 1]], 40);

        foreach ([$expired, $future, $off] as $c) {
            $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['combo_id' => $c->id, 'quantity' => 1]]])
                ->assertStatus(422)->assertJsonPath('code', 'combo_unavailable');
        }
        $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['combo_id' => $foreign->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'combo_not_in_branch');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unavailable_component_blocks_the_combo(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $x = $this->makeFoodItem($b, 100, ['is_available' => false]);
        $combo = $this->combo($b, [[$x, 1]], 90);

        $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['combo_id' => $combo->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'item_unavailable');
    }

    public function test_a_line_must_be_a_dish_or_a_combo_not_both_or_neither(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $x = $this->makeFoodItem($b, 100);
        $combo = $this->combo($b, [[$x, 1]], 90);

        $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/orders', ['source' => 'pos', 'items' => [['food_item_id' => $x->id, 'combo_id' => $combo->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_line');
    }

    // ---- management API ----

    public function test_combo_crud_replaces_items_and_is_audited(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $x = $this->makeFoodItem($b, 100);
        $y = $this->makeFoodItem($b, 50);

        $id = $this->postJson('/api/v1/combos', [
            'name' => 'Lunch', 'price' => 120, 'items' => [['food_item_id' => $x->id, 'quantity' => 1], ['food_item_id' => $y->id, 'quantity' => 2]],
        ])->assertStatus(201)->assertJsonPath('data.regularPrice', 200)->assertJsonCount(2, 'data.items')->json('data.id');

        $this->putJson("/api/v1/combos/{$id}", ['items' => [['food_item_id' => $y->id, 'quantity' => 1]]])
            ->assertStatus(200)->assertJsonCount(1, 'data.items');
        $this->postJson('/api/v1/combos', ['name' => 'Lunch', 'price' => 1, 'items' => [['food_item_id' => $x->id, 'quantity' => 1]]])->assertStatus(422);
        $this->deleteJson("/api/v1/combos/{$id}")->assertStatus(204);

        $this->assertDatabaseHas('audit_logs', ['action' => 'combo.created', 'subject_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'combo.deleted', 'subject_id' => $id]);
    }

    public function test_combo_validation_rejects_foreign_items_duplicates_and_bad_windows(): void
    {
        $user = $this->actingAsUserWithPermissions($this->perms);
        $b = $user->active_branch_id;
        $x = $this->makeFoodItem($b, 100);
        $foreign = $this->makeFoodItem($this->createBranch()->id, 100);

        $base = ['name' => 'C', 'price' => 10];
        $this->postJson('/api/v1/combos', $base + ['items' => [['food_item_id' => $foreign->id, 'quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/combos', $base + ['items' => [['food_item_id' => $x->id, 'quantity' => 1], ['food_item_id' => $x->id, 'quantity' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/combos', $base + ['items' => []])->assertStatus(422);
        $this->postJson('/api/v1/combos', $base + ['items' => [['food_item_id' => $x->id, 'quantity' => 1]], 'starts_at' => '2026-02-02', 'ends_at' => '2026-01-01'])->assertStatus(422);
        $this->postJson('/api/v1/combos', array_merge($base, ['price' => 1.234, 'items' => [['food_item_id' => $x->id, 'quantity' => 1]]]))->assertStatus(422);
    }

    public function test_combos_of_other_branches_are_invisible_and_untouchable(): void
    {
        $this->actingAsUserWithPermissions($this->perms);
        $foreign = $this->combo($this->createBranch()->id, [[$this->makeFoodItem(null, 10), 1]], 5);

        $this->getJson('/api/v1/combos')->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/combos/{$foreign->id}")->assertStatus(404);
    }
}
