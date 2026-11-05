<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveExpenseRequest;
use App\Models\AuditLog;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = Expense::query()->with('category')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->query('to')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->orderByDesc('expense_date');

        $perPage = min((int) $request->query('per_page', 30), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map($this->serialize(...)),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(SaveExpenseRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select an active branch first.'], 422);
        }

        $expense = Expense::create([...$request->validated(), 'branch_id' => $branchId, 'created_by' => $request->user()->id]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'expense.created',
            subjectType: Expense::class,
            subjectId: $expense->id,
            branchId: $expense->branch_id,
            changes: ['after' => $expense->only(['amount', 'category_id'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($expense->load('category'))], 201);
    }

    public function update(SaveExpenseRequest $request, Expense $expense)
    {
        if ($expense->reference_type) {
            return response()->json([
                'code' => 'expense_is_system_generated',
                'message' => 'This expense was recorded automatically and cannot be edited directly.',
            ], 422);
        }

        $before = $expense->only(['amount', 'category_id', 'description']);
        $expense->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'expense.updated',
            subjectType: Expense::class,
            subjectId: $expense->id,
            branchId: $expense->branch_id,
            changes: ['before' => $before, 'after' => $expense->fresh()->only(['amount', 'category_id', 'description'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($expense->fresh('category'))]);
    }

    public function destroy(Request $request, Expense $expense)
    {
        if ($expense->reference_type) {
            return response()->json([
                'code' => 'expense_is_system_generated',
                'message' => 'This expense was recorded automatically and cannot be deleted directly.',
            ], 422);
        }

        $expense->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'expense.deleted',
            subjectType: Expense::class,
            subjectId: $expense->id,
            branchId: $expense->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'branchId' => $expense->branch_id,
            'categoryId' => $expense->category_id,
            'categoryName' => $expense->category?->name,
            'amount' => (float) $expense->amount,
            'description' => $expense->description,
            'expenseDate' => $expense->expense_date->toDateString(),
            'isSystemGenerated' => (bool) $expense->reference_type,
            'createdAt' => $expense->created_at,
        ];
    }
}
