<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryAssignment;
use App\Models\Expense;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Support\DateRange;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only analytics computed live from the operational tables (orders,
 * order_items, delivery_assignments, customers, loyalty ledger, expenses).
 * There is no reporting warehouse, caching layer or pre-aggregation -- every
 * request runs real queries -- so the date range is capped (MAX_RANGE_DAYS)
 * to keep a single request bounded. See README "Reports & Analytics".
 *
 * Conventions shared by every report:
 *  - "Revenue" means orders whose status is not draft/cancelled/refunded,
 *    summed on grand_total (same rule as AccountingController/Dashboard).
 *  - `from`/`to` are inclusive calendar dates in the ACTIVE BRANCH's
 *    timezone (falling back to Asia/Dhaka), converted to the application's
 *    storage timezone for querying -- so "today" in Dhaka does not drift by
 *    six hours the way a naive DATE(created_at) on UTC timestamps would.
 *  - Day bucketing and delivery-time averages are done in PHP rather than
 *    with driver-specific SQL date functions, keeping the queries portable
 *    across MySQL / PostgreSQL / SQLite.
 */
class ReportController extends Controller
{
    private const EXCLUDED_STATUSES = ['draft', 'cancelled', 'refunded'];

    private const MAX_RANGE_DAYS = 366;

    public function sales(Request $request)
    {
        return response()->json(['data' => $this->salesData($request)]);
    }

    public function exportSales(Request $request): StreamedResponse
    {
        $data = $this->salesData($request);
        $filename = "sales-{$data['from']}-to-{$data['to']}.csv";

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Orders', 'Revenue (BDT)']);
            foreach ($data['byDay'] as $row) {
                fputcsv($out, [$row['date'], $row['orders'], number_format($row['revenue'], 2, '.', '')]);
            }
            fputcsv($out, ['Total', $data['totals']['orders'], number_format($data['totals']['revenue'], 2, '.', '')]);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function products(Request $request)
    {
        [$from, $to, $start, $end] = $this->range($request);
        $limit = min(max((int) $request->query('limit', 20), 1), 50);

        $rows = $this->orders($request, $start, $end)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw('order_items.name as name, SUM(order_items.quantity) as quantity, SUM(order_items.total) as revenue')
            ->groupBy('order_items.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'products' => $rows->map(fn ($r) => [
                'name' => $r->name,
                'quantity' => (int) $r->quantity,
                'revenue' => round((float) $r->revenue, 2),
            ])->values(),
        ]]);
    }

    public function categories(Request $request)
    {
        [$from, $to, $start, $end] = $this->range($request);

        $rows = $this->orders($request, $start, $end)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('food_items', 'food_items.id', '=', 'order_items.food_item_id')
            ->leftJoin('categories', 'categories.id', '=', 'food_items.category_id')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as category, SUM(order_items.quantity) as quantity, SUM(order_items.total) as revenue")
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->get();

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'categories' => $rows->map(fn ($r) => [
                'category' => $r->category,
                'quantity' => (int) $r->quantity,
                'revenue' => round((float) $r->revenue, 2),
            ])->values(),
        ]]);
    }

    public function delivery(Request $request)
    {
        [$from, $to, $start, $end] = $this->range($request);
        $branchId = $request->attributes->get('branch_id');

        $assignments = DeliveryAssignment::query()
            // withTrashed: a rider who has since been removed still owns
            // their historical deliveries.
            ->with(['rider' => fn ($q) => $q->withTrashed()->select('id', 'name')])
            ->when($branchId, fn ($q) => $q->where('delivery_assignments.branch_id', $branchId))
            ->whereBetween('delivery_assignments.created_at', [$start, $end])
            ->get(['id', 'rider_id', 'status', 'assigned_at', 'delivered_at']);

        $delivered = $assignments->where('status', 'delivered');
        $failed = $assignments->where('status', 'failed');
        $finished = $delivered->count() + $failed->count();

        // "Delivery time" = rider assigned -> delivered. Orders that were
        // delivered without ever being assigned a timestamp are left out of
        // the average rather than counted as zero.
        $minutes = $delivered
            ->filter(fn ($a) => $a->assigned_at && $a->delivered_at)
            ->map(fn ($a) => $a->assigned_at->diffInMinutes($a->delivered_at, true));

        $byRider = $assignments
            ->filter(fn ($a) => $a->rider_id !== null)
            ->groupBy('rider_id')
            ->map(function ($group) {
                return [
                    'rider' => $group->first()->rider?->name ?? 'Unknown rider',
                    'assigned' => $group->count(),
                    'delivered' => $group->where('status', 'delivered')->count(),
                    'failed' => $group->where('status', 'failed')->count(),
                ];
            })
            ->sortByDesc('delivered')
            ->values();

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'totals' => [
                'assignments' => $assignments->count(),
                'delivered' => $delivered->count(),
                'failed' => $failed->count(),
                'inProgress' => $assignments->count() - $finished,
                'successRate' => $finished > 0 ? round($delivered->count() / $finished * 100, 1) : null,
                'avgDeliveryMinutes' => $minutes->isNotEmpty() ? round($minutes->avg(), 1) : null,
            ],
            'byRider' => $byRider,
        ]]);
    }

    public function customers(Request $request)
    {
        [$from, $to, $start, $end] = $this->range($request);
        $branchId = $request->attributes->get('branch_id');

        // Customers may be chain-wide (branch_id null) -- same visibility
        // rule as CustomerController.
        $customerScope = fn ($q) => $q->when($branchId, fn ($q) => $q->where(
            fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')
        ));

        $newCustomers = Customer::query()
            ->tap($customerScope)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $spenders = $this->orders($request, $start, $end)
            ->whereNotNull('orders.customer_id')
            ->selectRaw('orders.customer_id as customer_id, COUNT(*) as orders, SUM(orders.grand_total) as spent')
            ->groupBy('orders.customer_id')
            ->orderByDesc('spent')
            ->limit(10)
            ->get();

        $names = Customer::withTrashed()
            ->whereIn('id', $spenders->pluck('customer_id'))
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');

        $visibleCustomerIds = Customer::withTrashed()->tap($customerScope)->select('id');
        $ledger = LoyaltyLedgerEntry::query()
            ->whereIn('customer_id', $visibleCustomerIds)
            ->whereBetween('created_at', [$start, $end]);

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'newCustomers' => $newCustomers,
            'topSpenders' => $spenders->map(fn ($r) => [
                'customerId' => $r->customer_id,
                'name' => $names[$r->customer_id]->name ?? 'Unknown customer',
                'phone' => $names[$r->customer_id]->phone ?? null,
                'orders' => (int) $r->orders,
                'spent' => round((float) $r->spent, 2),
            ])->values(),
            'loyalty' => [
                'pointsEarned' => (int) (clone $ledger)->where('type', 'earned')->sum('points_change'),
                'pointsRedeemed' => abs((int) (clone $ledger)->where('type', 'redeemed')->sum('points_change')),
            ],
        ]]);
    }

    /**
     * Side-by-side branch comparison. Deliberately NOT limited to the active
     * branch -- that is the point of the report -- but still authorized: a
     * Super Admin sees every branch, everyone else only the branches they
     * are assigned to (the same rule EnsureBranchScope applies to a single
     * X-Branch-Id), so reports.view alone never leaks another branch's
     * numbers.
     */
    public function branches(Request $request)
    {
        [$from, $to, $start, $end] = $this->range($request);
        $user = $request->user();

        $isSuperAdmin = $user->roles()->where('name', 'Super Admin')->exists();
        $branches = $isSuperAdmin
            ? Branch::query()->get(['id', 'name', 'code'])
            : $user->branches()->get(['branches.id', 'branches.name', 'branches.code']);
        $ids = $branches->pluck('id');

        $revenue = $this->orders($request, $start, $end, scoped: false)
            ->whereIn('orders.branch_id', $ids)
            ->selectRaw('orders.branch_id as branch_id, COUNT(*) as orders, SUM(orders.grand_total) as revenue')
            ->groupBy('orders.branch_id')
            ->get()
            ->keyBy('branch_id');

        $expenses = Expense::query()
            ->whereIn('branch_id', $ids)
            ->where('expense_date', '>=', $from)
            ->where('expense_date', '<=', $to)
            ->selectRaw('branch_id, SUM(amount) as total')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $rows = $branches->map(function ($b) use ($revenue, $expenses) {
            $rev = round((float) ($revenue[$b->id]->revenue ?? 0), 2);
            $exp = round((float) ($expenses[$b->id]->total ?? 0), 2);

            return [
                'branchId' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'orders' => (int) ($revenue[$b->id]->orders ?? 0),
                'revenue' => $rev,
                'expenses' => $exp,
                'netProfit' => round($rev - $exp, 2),
            ];
        })->sortByDesc('revenue')->values();

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'branches' => $rows,
        ]]);
    }

    // ------------------------------------------------------------------

    private function salesData(Request $request): array
    {
        [$from, $to, $start, $end, $tz] = $this->range($request);

        $totals = $this->orders($request, $start, $end)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(orders.grand_total), 0) as revenue, COALESCE(SUM(orders.discount_total), 0) as discounts, COALESCE(SUM(orders.tax_total), 0) as tax, COALESCE(SUM(orders.delivery_fee), 0) as delivery_fees')
            ->first();

        $orders = (int) $totals->orders;
        $revenue = round((float) $totals->revenue, 2);

        // Bucket by calendar day in the branch timezone (see class docblock),
        // pre-filling empty days so the series has no gaps.
        $days = [];
        $cursor = CarbonImmutable::parse($from, $tz);
        $last = CarbonImmutable::parse($to, $tz);
        for (; $cursor->lte($last); $cursor = $cursor->addDay()) {
            $days[$cursor->toDateString()] = ['date' => $cursor->toDateString(), 'orders' => 0, 'revenue' => 0.0];
        }
        $this->orders($request, $start, $end)
            ->get(['orders.created_at', 'orders.grand_total'])
            ->each(function ($o) use (&$days, $tz) {
                $key = $o->created_at->copy()->setTimezone($tz)->toDateString();
                if (isset($days[$key])) {
                    $days[$key]['orders']++;
                    $days[$key]['revenue'] += (float) $o->grand_total;
                }
            });
        foreach ($days as &$day) {
            $day['revenue'] = round($day['revenue'], 2);
        }
        unset($day);

        $bySource = $this->orders($request, $start, $end)
            ->selectRaw('orders.source as source, COUNT(*) as orders, SUM(orders.grand_total) as revenue')
            ->groupBy('orders.source')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'source' => $r->source,
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
            ])->values();

        return [
            'from' => $from,
            'to' => $to,
            'totals' => [
                'orders' => $orders,
                'revenue' => $revenue,
                'averageOrderValue' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                'discounts' => round((float) $totals->discounts, 2),
                'tax' => round((float) $totals->tax, 2),
                'deliveryFees' => round((float) $totals->delivery_fees, 2),
            ],
            'byDay' => array_values($days),
            'bySource' => $bySource,
        ];
    }

    /**
     * Revenue-countable orders in [start, end], branch-scoped unless told
     * otherwise. Soft-deleted orders are excluded by the model's scope.
     */
    private function orders(Request $request, Carbon|CarbonImmutable $start, Carbon|CarbonImmutable $end, bool $scoped = true)
    {
        $branchId = $request->attributes->get('branch_id');

        return Order::query()
            ->when($scoped && $branchId, fn ($q) => $q->where('orders.branch_id', $branchId))
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$start, $end]);
    }

    /**
     * Validates `from`/`to` and returns
     * [fromDate, toDate, startInStorageTz, endInStorageTz, branchTimezone].
     */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tz = DateRange::timezoneFor($request);
        $from = $request->query('from') ?: now($tz)->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now($tz)->toDateString();

        $startLocal = Carbon::parse($from, $tz)->startOfDay();
        $endLocal = Carbon::parse($to, $tz)->endOfDay();

        if ($endLocal->lt($startLocal)) {
            throw ValidationException::withMessages(['to' => 'The end date must not be before the start date.']);
        }
        if ($startLocal->diffInDays($endLocal) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'The date range cannot exceed '.self::MAX_RANGE_DAYS.' days.']);
        }

        [$start, $end] = DateRange::bounds($from, $to, $tz);

        return [$from, $to, $start, $end, $tz];
    }
}
