<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryAssignment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FoodItem;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Rider;
use App\Models\Role;
use Carbon\Carbon;
use Tests\TestCase;

class ReportTest extends TestCase
{
    private const FROM = '2026-03-01';

    private const TO = '2026-03-31';

    private function range(): string
    {
        return 'from='.self::FROM.'&to='.self::TO;
    }

    /** A Dhaka-local wall-clock time, converted to the app's storage timezone. */
    private function at(string $dhakaLocal): Carbon
    {
        return Carbon::parse($dhakaLocal, 'Asia/Dhaka')->setTimezone(config('app.timezone', 'UTC'));
    }

    private function makeOrder(Branch $branch, float $total, string $when = '2026-03-15 12:00:00', array $extra = []): Order
    {
        return Order::unguarded(fn () => Order::create(array_merge([
            'code' => 'CT-'.uniqid(),
            'source' => 'pos',
            'branch_id' => $branch->id,
            'subtotal' => $total,
            'grand_total' => $total,
            'status' => 'completed',
            'created_at' => $this->at($when),
            'updated_at' => $this->at($when),
        ], $extra)));
    }

    private function makeItem(string $categoryName, string $itemName, float $price = 100): FoodItem
    {
        $category = Category::firstOrCreate(
            ['name' => $categoryName],
            ['slug' => strtolower($categoryName).'-'.uniqid()]
        );

        return FoodItem::create([
            'category_id' => $category->id,
            'name' => $itemName,
            'slug' => strtolower($itemName).'-'.uniqid(),
            'base_price' => $price,
        ]);
    }

    private function addLine(Order $order, FoodItem $item, int $qty, float $lineTotal): void
    {
        OrderItem::create([
            'order_id' => $order->id,
            'food_item_id' => $item->id,
            'name' => $item->name,
            'quantity' => $qty,
            'unit_price' => $lineTotal / $qty,
            'total' => $lineTotal,
        ]);
    }

    public function test_sales_report_totals_and_breakdowns(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        $branch = $user->activeBranch;

        $this->makeOrder($branch, 100, '2026-03-10 12:00:00');
        $this->makeOrder($branch, 300, '2026-03-10 18:00:00');
        $this->makeOrder($branch, 200, '2026-03-12 12:00:00', ['source' => 'delivery']);
        // Outside the range and in another branch: neither may be counted.
        $this->makeOrder($branch, 999, '2026-02-20 12:00:00');
        $this->makeOrder($this->createBranch(), 888, '2026-03-10 12:00:00');

        $response = $this->getJson('/api/v1/reports/sales?'.$this->range())->assertStatus(200);

        $response->assertJsonPath('data.totals.orders', 3)
            ->assertJsonPath('data.totals.revenue', 600)
            ->assertJsonPath('data.totals.averageOrderValue', 200);

        $byDay = collect($response->json('data.byDay'))->keyBy('date');
        $this->assertCount(31, $byDay); // every day of March, empty days included
        $this->assertEquals(2, $byDay['2026-03-10']['orders']);
        $this->assertEquals(400, $byDay['2026-03-10']['revenue']);
        $this->assertEquals(0, $byDay['2026-03-11']['orders']);

        $bySource = collect($response->json('data.bySource'))->keyBy('source');
        $this->assertEquals(400, $bySource['pos']['revenue']);
        $this->assertEquals(200, $bySource['delivery']['revenue']);
    }

    public function test_day_boundaries_follow_the_branch_timezone_not_utc(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        // 02:00 on 11 March in Dhaka is still 10 March in UTC.
        $this->makeOrder($user->activeBranch, 500, '2026-03-11 02:00:00');

        $onTheEleventh = $this->getJson('/api/v1/reports/sales?from=2026-03-11&to=2026-03-11');
        $onTheTenth = $this->getJson('/api/v1/reports/sales?from=2026-03-10&to=2026-03-10');

        $onTheEleventh->assertJsonPath('data.totals.revenue', 500);
        $this->assertEquals('2026-03-11', $onTheEleventh->json('data.byDay.0.date'));
        $onTheTenth->assertJsonPath('data.totals.revenue', 0);
    }

    public function test_draft_cancelled_and_refunded_orders_are_not_revenue(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        $branch = $user->activeBranch;

        $this->makeOrder($branch, 100);
        $this->makeOrder($branch, 50, extra: ['status' => 'cancelled']);
        $this->makeOrder($branch, 60, extra: ['status' => 'draft']);
        $this->makeOrder($branch, 70, extra: ['status' => 'refunded']);

        $this->getJson('/api/v1/reports/sales?'.$this->range())
            ->assertJsonPath('data.totals.orders', 1)
            ->assertJsonPath('data.totals.revenue', 100);
    }

    public function test_sales_report_can_be_exported_as_csv(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        $this->makeOrder($user->activeBranch, 500, '2026-03-11 12:00:00');

        $response = $this->get('/api/v1/reports/sales/export?from=2026-03-10&to=2026-03-11')->assertStatus(200);

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('sales-2026-03-10-to-2026-03-11.csv', $response->headers->get('Content-Disposition'));

        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($response->streamedContent())));
        $this->assertSame(['Date', 'Orders', 'Revenue (BDT)'], $rows[0]);
        $this->assertContains(['2026-03-10', '0', '0.00'], $rows);
        $this->assertContains(['2026-03-11', '1', '500.00'], $rows);
        $this->assertSame(['Total', '1', '500.00'], end($rows));
    }

    public function test_product_ranking_is_by_revenue_and_respects_the_limit(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        $branch = $user->activeBranch;
        $burger = $this->makeItem('Mains', 'Burger');
        $fries = $this->makeItem('Sides', 'Fries');

        $a = $this->makeOrder($branch, 700);
        $this->addLine($a, $burger, 2, 600);
        $this->addLine($a, $fries, 1, 100);
        $b = $this->makeOrder($branch, 500);
        $this->addLine($b, $burger, 1, 300);
        $this->addLine($b, $fries, 2, 200);
        // A cancelled order's lines must not count.
        $c = $this->makeOrder($branch, 9000, extra: ['status' => 'cancelled']);
        $this->addLine($c, $fries, 90, 9000);

        $response = $this->getJson('/api/v1/reports/products?'.$this->range())->assertStatus(200);

        $response->assertJsonPath('data.products.0.name', 'Burger')
            ->assertJsonPath('data.products.0.quantity', 3)
            ->assertJsonPath('data.products.0.revenue', 900)
            ->assertJsonPath('data.products.1.name', 'Fries')
            ->assertJsonPath('data.products.1.quantity', 3)
            ->assertJsonPath('data.products.1.revenue', 300);

        $this->getJson('/api/v1/reports/products?limit=1&'.$this->range())
            ->assertJsonCount(1, 'data.products');
    }

    public function test_category_report_aggregates_line_totals(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        $branch = $user->activeBranch;
        $burger = $this->makeItem('Mains', 'Burger');
        $steak = $this->makeItem('Mains', 'Steak');
        $fries = $this->makeItem('Sides', 'Fries');

        $order = $this->makeOrder($branch, 1000);
        $this->addLine($order, $burger, 1, 300);
        $this->addLine($order, $steak, 1, 600);
        $this->addLine($order, $fries, 1, 100);

        $response = $this->getJson('/api/v1/reports/categories?'.$this->range())->assertStatus(200);

        $response->assertJsonPath('data.categories.0.category', 'Mains')
            ->assertJsonPath('data.categories.0.revenue', 900)
            ->assertJsonPath('data.categories.0.quantity', 2)
            ->assertJsonPath('data.categories.1.category', 'Sides')
            ->assertJsonPath('data.categories.1.revenue', 100);
    }

    public function test_delivery_report_counts_outcomes_and_average_time(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        $branch = $user->activeBranch;
        $karim = Rider::create(['branch_id' => $branch->id, 'name' => 'Karim', 'phone' => '017'.rand(10000000, 99999999)]);
        $rahim = Rider::create(['branch_id' => $branch->id, 'name' => 'Rahim', 'phone' => '018'.rand(10000000, 99999999)]);

        $make = function (string $status, ?Rider $rider, ?int $minutes) use ($branch) {
            $order = $this->makeOrder($branch, 200, '2026-03-15 12:00:00', ['source' => 'delivery']);
            $assignedAt = $this->at('2026-03-15 12:05:00');

            return DeliveryAssignment::unguarded(fn () => DeliveryAssignment::create([
                'order_id' => $order->id,
                'branch_id' => $branch->id,
                'rider_id' => $rider?->id,
                'status' => $status,
                'assigned_at' => $rider ? $assignedAt : null,
                'delivered_at' => $status === 'delivered' ? $assignedAt->copy()->addMinutes($minutes) : null,
                'failed_at' => $status === 'failed' ? $assignedAt->copy()->addMinutes(10) : null,
                'created_at' => $this->at('2026-03-15 12:00:00'),
                'updated_at' => $this->at('2026-03-15 12:00:00'),
            ]));
        };

        $make('delivered', $karim, 30);
        $make('delivered', $karim, 50);
        $make('failed', $rahim, null);
        $make('pending', null, null);

        $response = $this->getJson('/api/v1/reports/delivery?'.$this->range())->assertStatus(200);

        $response->assertJsonPath('data.totals.assignments', 4)
            ->assertJsonPath('data.totals.delivered', 2)
            ->assertJsonPath('data.totals.failed', 1)
            ->assertJsonPath('data.totals.inProgress', 1)
            ->assertJsonPath('data.totals.avgDeliveryMinutes', 40)
            // 2 delivered out of 3 finished attempts
            ->assertJsonPath('data.totals.successRate', 66.7);

        $byRider = collect($response->json('data.byRider'))->keyBy('rider');
        $this->assertCount(2, $byRider); // the unassigned order has no rider row
        $this->assertEquals(2, $byRider['Karim']['delivered']);
        $this->assertEquals(1, $byRider['Rahim']['failed']);
    }

    public function test_delivery_report_is_null_not_zero_when_there_is_nothing_to_average(): void
    {
        $this->actingAsUserWithPermissions(['reports.view']);

        $this->getJson('/api/v1/reports/delivery?'.$this->range())
            ->assertJsonPath('data.totals.assignments', 0)
            ->assertJsonPath('data.totals.successRate', null)
            ->assertJsonPath('data.totals.avgDeliveryMinutes', null);
    }

    public function test_customer_report_ranks_spenders_and_totals_loyalty_points(): void
    {
        $user = $this->actingAsUserWithPermissions(['reports.view']);
        $branch = $user->activeBranch;

        $big = Customer::create(['name' => 'Big Spender', 'phone' => '0171'.rand(1000000, 9999999)]);
        $small = Customer::create(['name' => 'Small Spender', 'phone' => '0172'.rand(1000000, 9999999)]);
        Customer::unguarded(fn () => Customer::create([
            'name' => 'Old Customer', 'phone' => '0173'.rand(1000000, 9999999),
            'created_at' => $this->at('2025-01-01 10:00:00'),
        ]));

        $this->makeOrder($branch, 400, extra: ['customer_id' => $big->id]);
        $this->makeOrder($branch, 600, extra: ['customer_id' => $big->id]);
        $this->makeOrder($branch, 250, extra: ['customer_id' => $small->id]);
        $this->makeOrder($branch, 5000, extra: ['customer_id' => $small->id, 'status' => 'cancelled']);
        $this->makeOrder($branch, 111); // walk-in, no customer

        LoyaltyLedgerEntry::record($big, 'earned', 100);
        LoyaltyLedgerEntry::record($small, 'earned', 20);
        LoyaltyLedgerEntry::record($big, 'redeemed', -30);

        // Customer.created_at defaults to "now" -- query a window that holds it.
        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->getJson("/api/v1/reports/customers?from={$from}&to={$to}")->assertStatus(200);
        $response->assertJsonPath('data.newCustomers', 2)
            ->assertJsonPath('data.loyalty.pointsEarned', 120)
            ->assertJsonPath('data.loyalty.pointsRedeemed', 30);

        $march = $this->getJson('/api/v1/reports/customers?'.$this->range())->assertStatus(200);
        $march->assertJsonPath('data.topSpenders.0.name', 'Big Spender')
            ->assertJsonPath('data.topSpenders.0.orders', 2)
            ->assertJsonPath('data.topSpenders.0.spent', 1000)
            ->assertJsonPath('data.topSpenders.1.name', 'Small Spender')
            ->assertJsonPath('data.topSpenders.1.spent', 250) // the cancelled 5000 is excluded
            ->assertJsonCount(2, 'data.topSpenders'); // walk-in orders are not a "customer"
    }

    public function test_branch_comparison_covers_only_branches_the_user_may_access(): void
    {
        $mine = $this->createBranch(['name' => 'Gulshan']);
        $user = $this->actingAsUserWithPermissions(['reports.view'], $mine);
        $second = $this->createBranch(['name' => 'Banani']);
        $user->branches()->attach($second->id);
        $foreign = $this->createBranch(['name' => 'Chattogram']);

        $this->makeOrder($mine, 400);
        $this->makeOrder($second, 900);
        $this->makeOrder($foreign, 5000);
        $category = ExpenseCategory::create(['branch_id' => $second->id, 'name' => 'Rent']);
        Expense::create(['branch_id' => $second->id, 'category_id' => $category->id, 'amount' => 250, 'expense_date' => '2026-03-05']);

        $response = $this->getJson('/api/v1/reports/branches?'.$this->range())->assertStatus(200);

        $rows = collect($response->json('data.branches'));
        $this->assertCount(2, $rows);
        $this->assertNull($rows->firstWhere('name', 'Chattogram'));
        // Sorted by revenue, highest first.
        $this->assertEquals('Banani', $rows[0]['name']);
        $this->assertEquals(900, $rows[0]['revenue']);
        $this->assertEquals(250, $rows[0]['expenses']);
        $this->assertEquals(650, $rows[0]['netProfit']);
        $this->assertEquals('Gulshan', $rows[1]['name']);
        $this->assertEquals(400, $rows[1]['netProfit']);
    }

    public function test_a_super_admin_sees_every_branch_in_the_comparison(): void
    {
        $mine = $this->createBranch(['name' => 'Gulshan']);
        $user = $this->actingAsUserWithPermissions(['reports.view'], $mine);
        $user->roles()->attach(Role::create(['name' => 'Super Admin'])->id);
        $other = $this->createBranch(['name' => 'Chattogram']);
        $this->makeOrder($other, 5000);

        $rows = collect($this->getJson('/api/v1/reports/branches?'.$this->range())->json('data.branches'));

        $this->assertEquals(5000, $rows->firstWhere('name', 'Chattogram')['revenue']);
    }

    public function test_invalid_or_oversized_date_ranges_are_rejected(): void
    {
        $this->actingAsUserWithPermissions(['reports.view']);

        $this->getJson('/api/v1/reports/sales?from=2026-03-20&to=2026-03-10')->assertStatus(422);
        $this->getJson('/api/v1/reports/sales?from=not-a-date&to=2026-03-10')->assertStatus(422);
        $this->getJson('/api/v1/reports/sales?from=2024-01-01&to=2026-03-10')->assertStatus(422);
    }

    public function test_reports_require_the_reports_view_permission(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);

        foreach (['sales', 'sales/export', 'products', 'categories', 'delivery', 'customers', 'branches'] as $report) {
            $this->getJson("/api/v1/reports/{$report}")->assertStatus(403);
        }
    }
}
