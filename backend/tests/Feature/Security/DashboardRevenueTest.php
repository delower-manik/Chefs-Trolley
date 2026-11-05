<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\Order;
use Tests\TestCase;

class DashboardRevenueTest extends TestCase
{
    public function test_sales_total_is_only_shown_to_people_who_may_see_reports(): void
    {
        $this->actingAsUserWithPermissions(['orders.view']);
        $this->getJson('/api/v1/dashboard/metrics')->assertOk()->assertJsonPath('data.todaySales', null)->assertJsonStructure(['data' => ['ordersToday']]);
        Order::create(['code' => 'CT-D1', 'source' => 'pos', 'branch_id' => auth()->user()?->active_branch_id ?? Branch::first()->id, 'status' => 'pending', 'kitchen_state' => 'new', 'grand_total' => 12345]);
        // the recent-orders list must not leak per-order totals either
        $recent = $this->getJson('/api/v1/dashboard/metrics')->json('data.recentOrders');
        $this->assertNotEmpty($recent);
        foreach ($recent as $o) {
            $this->assertNull($o['total']);
        }

        $this->actingAsUserWithPermissions(['reports.view'], Branch::first());
        $this->assertNotNull($this->getJson('/api/v1/dashboard/metrics')->assertOk()->json('data.todaySales'));
    }
}
