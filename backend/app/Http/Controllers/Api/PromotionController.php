<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePromotionRequest;
use App\Models\AuditLog;
use App\Models\Promotion;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $promotions = Promotion::query()
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $promotions->map($this->serialize(...))]);
    }

    public function store(SavePromotionRequest $request)
    {
        $promotion = Promotion::create($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'promotion.created',
            subjectType: Promotion::class,
            subjectId: $promotion->id,
            branchId: $promotion->branch_id,
            changes: ['after' => $promotion->only(['code', 'type', 'value'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($promotion)], 201);
    }

    public function update(SavePromotionRequest $request, Promotion $promotion)
    {
        $before = $promotion->only(['is_active', 'value', 'usage_limit']);
        $promotion->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'promotion.updated',
            subjectType: Promotion::class,
            subjectId: $promotion->id,
            branchId: $promotion->branch_id,
            changes: ['before' => $before, 'after' => $promotion->fresh()->only(['is_active', 'value', 'usage_limit'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($promotion->fresh())]);
    }

    public function destroy(Request $request, Promotion $promotion)
    {
        $promotion->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'promotion.deleted',
            subjectType: Promotion::class,
            subjectId: $promotion->id,
            branchId: $promotion->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'branchId' => $promotion->branch_id,
            'code' => $promotion->code,
            'name' => $promotion->name,
            'type' => $promotion->type,
            'value' => (float) $promotion->value,
            'minOrderAmount' => (float) $promotion->min_order_amount,
            'maxDiscountAmount' => $promotion->max_discount_amount !== null ? (float) $promotion->max_discount_amount : null,
            'usageLimit' => $promotion->usage_limit,
            'usageLimitPerCustomer' => $promotion->usage_limit_per_customer,
            'usedCount' => $promotion->used_count,
            'startsAt' => $promotion->starts_at,
            'expiresAt' => $promotion->expires_at,
            'isActive' => $promotion->is_active,
        ];
    }
}
