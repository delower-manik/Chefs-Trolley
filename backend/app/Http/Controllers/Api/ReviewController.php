<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveReviewRequest;
use App\Models\AuditLog;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = Review::query()->with('customer')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->query('rating')))
            ->when($request->has('published'), fn ($q) => $q->where('is_published', $request->boolean('published')))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 20), 100);
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

    public function store(SaveReviewRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return response()->json(['code' => 'branch_required', 'message' => 'Select an active branch first.'], 422);
        }

        $review = Review::create([...$request->validated(), 'branch_id' => $branchId]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'review.created',
            subjectType: Review::class,
            subjectId: $review->id,
            branchId: $review->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($review->load('customer'))], 201);
    }

    /**
     * POST /reviews/{review}/publish and /unpublish
     * Moderation is a separate, explicit action from editing content --
     * publishing what a customer wrote is a distinct, auditable decision.
     */
    public function publish(Request $request, Review $review)
    {
        return $this->setPublished($request, $review, true);
    }

    public function unpublish(Request $request, Review $review)
    {
        return $this->setPublished($request, $review, false);
    }

    private function setPublished(Request $request, Review $review, bool $published): JsonResponse
    {
        $review->update(['is_published' => $published]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: $published ? 'review.published' : 'review.unpublished',
            subjectType: Review::class,
            subjectId: $review->id,
            branchId: $review->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($review->fresh('customer'))]);
    }

    public function destroy(Request $request, Review $review)
    {
        $review->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'review.deleted',
            subjectType: Review::class,
            subjectId: $review->id,
            branchId: $review->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Review $review): array
    {
        return [
            'id' => $review->id,
            'branchId' => $review->branch_id,
            'orderId' => $review->order_id,
            'customerId' => $review->customer_id,
            'reviewerName' => $review->customer?->name ?? $review->reviewer_name,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'isPublished' => $review->is_published,
            'createdAt' => $review->created_at,
        ];
    }
}
