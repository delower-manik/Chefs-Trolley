<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * A lightweight revenue-vs-expenses view -- NOT a full accounting system.
 * No general ledger, no double-entry bookkeeping, no tax computation. It
 * answers "how much came in, how much went out, what's left" for a date
 * range from data that already exists (Orders, Expenses) rather than
 * inventing a parallel bookkeeping model. See README for what a real
 * Accounting module would still need.
 */
class AccountingController extends Controller
{
    public function summary(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $revenue = (float) Order::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('status', ['draft', 'cancelled', 'refunded'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->sum('grand_total');

        $expensesByCategory = Expense::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->with('category')
            ->get()
            ->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorized')
            ->map(fn ($group) => (float) $group->sum('amount'))
            ->sortDesc();

        $totalExpenses = (float) $expensesByCategory->sum();

        return response()->json([
            'data' => [
                'from' => $from,
                'to' => $to,
                'totalRevenue' => $revenue,
                'totalExpenses' => $totalExpenses,
                'netProfit' => Money::toFloat(Money::toMinor($revenue) - Money::toMinor($totalExpenses)),
                'expensesByCategory' => $expensesByCategory->map(fn ($amount, $name) => [
                    'category' => $name,
                    'amount' => $amount,
                ])->values(),
            ],
        ]);
    }
}
