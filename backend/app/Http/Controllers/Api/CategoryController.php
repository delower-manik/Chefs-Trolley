<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCategoryRequest;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $categories = Category::query()
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->withCount('foodItems')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories->map($this->serialize(...))]);
    }

    public function store(SaveCategoryRequest $request)
    {
        $category = Category::create([
            ...$request->validated(),
            'slug' => $request->slug(),
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'category.created',
            subjectType: Category::class,
            subjectId: $category->id,
            branchId: $category->branch_id,
            changes: ['after' => $category->only(['name', 'is_active'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($category)], 201);
    }

    public function update(SaveCategoryRequest $request, Category $category)
    {
        $before = $category->only(['name', 'is_active', 'sort_order']);

        $category->update([
            ...$request->validated(),
            'slug' => $request->slug(),
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'category.updated',
            subjectType: Category::class,
            subjectId: $category->id,
            branchId: $category->branch_id,
            changes: ['before' => $before, 'after' => $category->only(['name', 'is_active', 'sort_order'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($category->fresh())]);
    }

    public function destroy(Request $request, Category $category)
    {
        if ($category->foodItems()->exists()) {
            return response()->json([
                'code' => 'category_not_empty',
                'message' => 'This category still has food items in it. Move or remove them first.',
            ], 422);
        }

        $category->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'category.deleted',
            subjectType: Category::class,
            subjectId: $category->id,
            branchId: $category->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Category $category): array
    {
        return [
            'id' => $category->id,
            'branchId' => $category->branch_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'sortOrder' => $category->sort_order,
            'isActive' => $category->is_active,
            'foodItemCount' => $category->food_items_count ?? null,
        ];
    }
}
