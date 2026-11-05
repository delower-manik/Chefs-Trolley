<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveExpenseCategoryRequest;
use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $categories = ExpenseCategory::query()
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories->map(fn ($c) => ['id' => $c->id, 'branchId' => $c->branch_id, 'name' => $c->name])]);
    }

    public function store(SaveExpenseCategoryRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $category = ExpenseCategory::create(['branch_id' => $branchId, 'name' => $request->validated('name')]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'expense_category.created',
            subjectType: ExpenseCategory::class,
            subjectId: $category->id,
            branchId: $category->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => ['id' => $category->id, 'branchId' => $category->branch_id, 'name' => $category->name]], 201);
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->expenses()->exists()) {
            return response()->json([
                'code' => 'category_has_expenses',
                'message' => 'This category has expenses recorded against it and cannot be deleted.',
            ], 422);
        }

        $expenseCategory->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'expense_category.deleted',
            subjectType: ExpenseCategory::class,
            subjectId: $expenseCategory->id,
            branchId: $expenseCategory->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }
}
