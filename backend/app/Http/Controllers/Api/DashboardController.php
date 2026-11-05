<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Order;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Real aggregate queries against the orders table -- no mocked numbers.
     * Kitchen-open-count and stock-alert-count will be joined in once the
     * KDS (Phase 3) and Inventory (Phase 4) modules land; until then they
     * report 0 rather than a fabricated figure.
     */
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $todayStart = now($request->user()->activeBranch?->timezone ?? 'Asia/Dhaka')->startOfDay();

        $todayOrders = Order::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('created_at', '>=', $todayStart);

        $recent = (clone $todayOrders)->orderByDesc('created_at')->limit(5)->get();
        $canSeeMoney = $request->user()->hasPermissionKey('reports.view');

        $stockAlerts = Ingredient::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->lowStock()
            ->count();

        return response()->json([
            'data' => [
                // Revenue is financial data: floor staff see the operational counts, not the sales total.
                'todaySales' => $canSeeMoney ? (float) (clone $todayOrders)->whereNotIn('status', ['cancelled', 'refunded'])->sum('grand_total') : null,
                'ordersToday' => (clone $todayOrders)->count(),
                'openKitchenOrders' => Order::query()
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->whereNotIn('status', ['draft', 'cancelled', 'refunded'])
                    ->whereIn('kitchen_state', ['new', 'accepted', 'preparing', 'ready'])
                    ->count(),
                'stockAlerts' => $stockAlerts,
                'salesByHour' => [],
                'recentOrders' => $recent->map(fn ($o) => [
                    'code' => $o->code,
                    'type' => $o->source,
                    'total' => $canSeeMoney ? (float) $o->grand_total : null,
                ]),
            ],
        ]);
    }
}
