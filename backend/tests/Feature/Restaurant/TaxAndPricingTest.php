<?php

namespace Tests\Feature\Restaurant;

use App\Models\Category;
use App\Models\Promotion;
use App\Models\TaxRate;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsMenu;
use Tests\TestCase;

class TaxAndPricingTest extends TestCase
{
    use BuildsMenu;

    private function order(array $items, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/orders', array_merge(['source' => 'pos', 'items' => $items], $extra));
    }

    public function test_exclusive_tax_is_added_on_top_of_the_subtotal(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        $rate = TaxRate::create(['branch_id' => $user->active_branch_id, 'name' => 'VAT 15', 'rate' => 15]);
        $item = $this->makeFoodItem($user->active_branch_id, 100, ['tax_rate_id' => $rate->id]);

        $this->order([['food_item_id' => $item->id, 'quantity' => 2]])
            ->assertStatus(201)
            ->assertJsonPath('data.subtotal', 200)
            ->assertJsonPath('data.taxTotal', 30)
            ->assertJsonPath('data.taxInclusiveTotal', 0)
            ->assertJsonPath('data.grandTotal', 230);
    }

    public function test_inclusive_tax_is_reported_but_not_added_again(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        $rate = TaxRate::create(['branch_id' => $user->active_branch_id, 'name' => 'VAT 15 incl', 'rate' => 15, 'is_inclusive' => true]);
        $item = $this->makeFoodItem($user->active_branch_id, 115, ['tax_rate_id' => $rate->id]);

        $this->order([['food_item_id' => $item->id, 'quantity' => 1]])
            ->assertStatus(201)
            ->assertJsonPath('data.subtotal', 115)
            ->assertJsonPath('data.taxTotal', 15)
            ->assertJsonPath('data.taxInclusiveTotal', 15)
            ->assertJsonPath('data.grandTotal', 115);
    }

    public function test_branch_default_rate_applies_to_items_without_their_own(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        TaxRate::create(['branch_id' => $user->active_branch_id, 'name' => 'Default', 'rate' => 10, 'is_default' => true]);
        $plain = $this->makeFoodItem($user->active_branch_id, 100);

        $this->order([['food_item_id' => $plain->id, 'quantity' => 1]])
            ->assertStatus(201)->assertJsonPath('data.taxTotal', 10)->assertJsonPath('data.grandTotal', 110);
    }

    public function test_inactive_default_rate_is_ignored_and_legacy_item_rate_still_works(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        TaxRate::create(['branch_id' => $user->active_branch_id, 'name' => 'Old', 'rate' => 10, 'is_default' => true, 'is_active' => false]);
        $legacy = $this->makeFoodItem($user->active_branch_id, 200, ['tax_rate' => 5]);
        $plain = $this->makeFoodItem($user->active_branch_id, 100);

        $this->order([
            ['food_item_id' => $legacy->id, 'quantity' => 1],
            ['food_item_id' => $plain->id, 'quantity' => 1],
        ])->assertStatus(201)->assertJsonPath('data.taxTotal', 10)->assertJsonPath('data.grandTotal', 310);
    }

    public function test_a_rate_from_another_branch_never_applies(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        $other = $this->createBranch();
        TaxRate::create(['branch_id' => $other->id, 'name' => 'Elsewhere', 'rate' => 50, 'is_default' => true]);
        $item = $this->makeFoodItem($user->active_branch_id, 100);

        $this->order([['food_item_id' => $item->id, 'quantity' => 1]])
            ->assertStatus(201)->assertJsonPath('data.taxTotal', 0);
    }

    public function test_money_is_computed_in_whole_paisa_without_float_drift(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        $rate = TaxRate::create(['branch_id' => $user->active_branch_id, 'name' => 'VAT 7.5', 'rate' => 7.5]);
        $item = $this->makeFoodItem($user->active_branch_id, '33.33', ['tax_rate_id' => $rate->id]);

        // 3 x 33.33 = 99.99; 7.5% = 7.49925 -> 7.50 (half-up on paisa)
        $this->order([['food_item_id' => $item->id, 'quantity' => 3]])
            ->assertStatus(201)
            ->assertJsonPath('data.subtotal', 99.99)
            ->assertJsonPath('data.taxTotal', 7.5)
            ->assertJsonPath('data.grandTotal', 107.49);
    }

    public function test_percentage_promo_and_tax_combine_correctly(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        TaxRate::create(['branch_id' => $user->active_branch_id, 'name' => 'VAT', 'rate' => 10, 'is_default' => true]);
        Promotion::create([
            'code' => 'TEN', 'name' => 'Ten off', 'type' => 'percentage', 'value' => 10, 'is_active' => true,
        ]);
        $item = $this->makeFoodItem($user->active_branch_id, 100);

        // subtotal 100, tax 10 (on pre-discount total), discount 10 -> 100
        $this->order([['food_item_id' => $item->id, 'quantity' => 1]], ['promo_code' => 'TEN'])
            ->assertStatus(201)
            ->assertJsonPath('data.discountTotal', 10)
            ->assertJsonPath('data.grandTotal', 100);
    }

    public function test_an_item_from_another_branch_cannot_be_ordered(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create']);
        $other = $this->createBranch();
        $foreign = $this->makeFoodItem($other->id, 100);

        $this->order([['food_item_id' => $foreign->id, 'quantity' => 1]])
            ->assertStatus(422)->assertJsonPath('code', 'item_not_in_branch');
    }

    public function test_discount_can_never_push_the_total_below_zero(): void
    {
        $this->actingAsUserWithPermissions(['orders.create', 'discounts.override']);
        $item = $this->makeFoodItem(null, 100);

        // Phase 13: a manual discount beyond what is owed is refused outright (it used to be silently clamped).
        $this->order([['food_item_id' => $item->id, 'quantity' => 1]], ['discount_total' => 5000, 'discount_reason' => 'Test'])
            ->assertStatus(422)->assertJsonPath('code', 'discount_exceeds_total');

        // A 100% discount with override is allowed and lands exactly on zero.
        $this->order([['food_item_id' => $item->id, 'quantity' => 1]], ['discount_total' => 100, 'discount_reason' => 'Owner meal'])
            ->assertStatus(201)->assertJsonPath('data.discountTotal', 100)->assertJsonPath('data.grandTotal', 0);
    }

    // ---- Tax rate management API ----

    public function test_tax_rates_need_the_tax_manage_permission(): void
    {
        $this->actingAsUserWithPermissions(['menu.manage']);

        $this->getJson('/api/v1/tax-rates')->assertStatus(403);
        $this->postJson('/api/v1/tax-rates', ['name' => 'X', 'rate' => 5])->assertStatus(403);
    }

    public function test_creating_a_default_rate_demotes_the_previous_default(): void
    {
        $this->actingAsUserWithPermissions(['tax.manage']);

        $a = $this->postJson('/api/v1/tax-rates', ['name' => 'A', 'rate' => 5, 'is_default' => true])->assertStatus(201);
        $b = $this->postJson('/api/v1/tax-rates', ['name' => 'B', 'rate' => 7.5, 'is_default' => true])->assertStatus(201);

        $this->assertFalse(TaxRate::find($a->json('data.id'))->is_default);
        $this->assertTrue(TaxRate::find($b->json('data.id'))->is_default);
        $this->assertSame(1, TaxRate::where('is_default', true)->count());
    }

    public function test_tax_rate_validation_and_per_branch_name_uniqueness(): void
    {
        $this->actingAsUserWithPermissions(['tax.manage']);

        $this->postJson('/api/v1/tax-rates', ['name' => 'VAT', 'rate' => 5])->assertStatus(201);
        $this->postJson('/api/v1/tax-rates', ['name' => 'VAT', 'rate' => 7])->assertStatus(422);
        $this->postJson('/api/v1/tax-rates', ['name' => 'Bad', 'rate' => 101])->assertStatus(422);
        $this->postJson('/api/v1/tax-rates', ['name' => 'Bad2', 'rate' => -1])->assertStatus(422);
        $this->postJson('/api/v1/tax-rates', ['name' => 'Bad3', 'rate' => 5.123])->assertStatus(422);
    }

    public function test_tax_rate_changes_are_audited_and_listing_is_branch_scoped(): void
    {
        $user = $this->actingAsUserWithPermissions(['tax.manage']);
        $other = $this->createBranch();
        TaxRate::create(['branch_id' => $other->id, 'name' => 'Hidden', 'rate' => 9]);

        $id = $this->postJson('/api/v1/tax-rates', ['name' => 'Mine', 'rate' => 5])->json('data.id');
        $this->putJson("/api/v1/tax-rates/{$id}", ['rate' => 6])->assertStatus(200)->assertJsonPath('data.rate', 6);

        $this->assertDatabaseHas('audit_logs', ['action' => 'tax_rate.created', 'subject_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tax_rate.updated', 'subject_id' => $id]);
        $this->getJson('/api/v1/tax-rates')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_food_item_can_only_reference_a_tax_rate_of_the_active_branch(): void
    {
        $user = $this->actingAsUserWithPermissions(['menu.manage']);
        $other = $this->createBranch();
        $foreign = TaxRate::create(['branch_id' => $other->id, 'name' => 'Foreign', 'rate' => 5]);
        $category = Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()]);

        $this->postJson('/api/v1/food-items', [
            'category_id' => $category->id, 'name' => 'Soup', 'base_price' => 100, 'tax_rate_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('tax_rate_id');
    }
}
