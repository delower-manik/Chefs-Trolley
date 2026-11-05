<?php

namespace Tests\Feature\Accounting;

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\FoodItem;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    public function test_summary_computes_revenue_minus_expenses(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'finance.manage']);
        $category = Category::create(['name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $item = FoodItem::create(['category_id' => $category->id, 'name' => 'Burger', 'slug' => 'burger-'.uniqid(), 'base_price' => 500]);

        $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $expenseCategory = ExpenseCategory::create(['branch_id' => $user->activeBranch->id, 'name' => 'Utilities']);
        $this->postJson('/api/v1/expenses', [
            'category_id' => $expenseCategory->id, 'amount' => 150, 'expense_date' => now()->toDateString(),
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/accounting/summary?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString());

        $response->assertJsonPath('data.totalRevenue', 500)
            ->assertJsonPath('data.totalExpenses', 150)
            ->assertJsonPath('data.netProfit', 350);
    }

    public function test_a_cancelled_order_does_not_count_as_revenue(): void
    {
        $user = $this->actingAsUserWithPermissions(['orders.create', 'orders.view', 'orders.cancel', 'finance.manage']);
        $category = Category::create(['name' => 'Snacks', 'slug' => 'snacks-'.uniqid()]);
        $item = FoodItem::create(['category_id' => $category->id, 'name' => 'Fries', 'slug' => 'fries-'.uniqid(), 'base_price' => 100]);

        $order = $this->postJson('/api/v1/orders', [
            'source' => 'pos', 'items' => [['food_item_id' => $item->id, 'quantity' => 1]],
        ])->json('data');
        $this->postJson("/api/v1/orders/{$order['id']}/cancel")->assertStatus(200);

        $response = $this->getJson('/api/v1/accounting/summary?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString());

        $response->assertJsonPath('data.totalRevenue', 0);
    }

    public function test_two_expense_categories_in_the_same_branch_cannot_share_a_name(): void
    {
        $this->actingAsUserWithPermissions(['finance.manage']);

        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent'])->assertStatus(201);
        $this->postJson('/api/v1/expense-categories', ['name' => 'Rent'])->assertStatus(422);
    }

    public function test_a_category_with_expenses_cannot_be_deleted(): void
    {
        $user = $this->actingAsUserWithPermissions(['finance.manage']);
        $category = ExpenseCategory::create(['branch_id' => $user->activeBranch->id, 'name' => 'Maintenance']);

        $this->postJson('/api/v1/expenses', [
            'category_id' => $category->id, 'amount' => 500, 'expense_date' => now()->toDateString(),
        ])->assertStatus(201);

        $this->deleteJson("/api/v1/expense-categories/{$category->id}")
            ->assertStatus(422)->assertJsonPath('code', 'category_has_expenses');
    }
}
