<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\Review;
use App\Services\AppSettings;
use Illuminate\Http\Request;

/** Loyalty, reservations and reviews for the signed-in customer. */
class AccountController extends Controller
{
    public function loyalty(Request $request)
    {
        $c = $request->attributes->get('customer');

        return response()->json(['data' => [
            'points' => (int) $c->fresh()->loyalty_points,
            'history' => $c->loyaltyEntries()->latest('created_at')->limit(30)->get()
                ->map(fn ($e) => ['type' => $e->type, 'points' => (int) $e->points_change, 'balance' => (int) $e->balance_after, 'reason' => $e->reason, 'date' => $e->created_at]),
        ]]);
    }

    public function reservations(Request $request)
    {
        $rows = Reservation::where('customer_id', $request->attributes->get('customer')->id)->latest('reserved_for')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->reservation($r))]);
    }

    /** POST /web/reservations — a REQUEST: it starts as "pending" and the restaurant confirms it. No table is promised. */
    public function reserve(Request $request, AppSettings $settings)
    {
        if (! $settings->enabled('reservations')) {
            return response()->json(['code' => 'feature_disabled', 'message' => 'Table reservations are not available.'], 403);
        }
        $c = $request->attributes->get('customer');
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'party_size' => ['required', 'integer', 'min:1', 'max:30'],
            'reserved_for' => ['required', 'date', 'after:now', 'before:'.now()->addDays(60)->toDateString()],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);
        $branch = Branch::where('id', $data['branch_id'])->where('is_active', true)->first();
        if (! $branch) {
            return response()->json(['code' => 'invalid_branch', 'message' => 'That branch is not available.'], 422);
        }
        $pending = Reservation::where('customer_id', $c->id)->whereIn('status', ['pending', 'confirmed'])->where('reserved_for', '>', now())->count();
        if ($pending >= 3) {
            return response()->json(['code' => 'too_many_reservations', 'message' => 'You already have upcoming reservations. Please cancel one or call us.'], 422);
        }

        $r = Reservation::create([
            'branch_id' => $branch->id, 'customer_id' => $c->id, 'customer_name' => $c->name, 'customer_phone' => $c->phone,
            'party_size' => $data['party_size'], 'reserved_for' => $data['reserved_for'], 'status' => 'pending', 'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $this->reservation($r)], 201);
    }

    public function cancelReservation(Request $request, string $reservation)
    {
        $r = Reservation::where('id', $reservation)->where('customer_id', $request->attributes->get('customer')->id)->firstOrFail();
        if (! in_array($r->status, ['pending', 'confirmed'], true)) {
            return response()->json(['code' => 'cannot_cancel', 'message' => 'This reservation can no longer be cancelled.'], 422);
        }
        $r->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->reservation($r->fresh())]);
    }

    /** POST /web/orders/{order}/review — only for the customer's own finished order, once. Starts unpublished (a manager approves it). */
    public function review(Request $request, string $order)
    {
        $c = $request->attributes->get('customer');
        $o = Order::where('id', $order)->where('customer_id', $c->id)->firstOrFail();
        if (! in_array($o->status, ['completed', 'delivered'], true)) {
            return response()->json(['code' => 'order_not_finished', 'message' => 'You can review an order once it is finished.'], 422);
        }
        if (Review::where('order_id', $o->id)->exists()) {
            return response()->json(['code' => 'already_reviewed', 'message' => 'You have already reviewed this order.'], 422);
        }
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);

        $r = Review::create([
            'branch_id' => $o->branch_id, 'order_id' => $o->id, 'customer_id' => $c->id, 'reviewer_name' => $c->name,
            'rating' => $data['rating'], 'comment' => isset($data['comment']) ? strip_tags($data['comment']) : null, 'is_published' => false,
        ]);

        return response()->json(['data' => ['id' => $r->id, 'rating' => (int) $r->rating, 'published' => false, 'message' => 'Thank you! Your review will appear once the restaurant approves it.']], 201);
    }

    private function reservation(Reservation $r): array
    {
        return [
            'id' => $r->id, 'branchId' => $r->branch_id, 'partySize' => (int) $r->party_size, 'reservedFor' => $r->reserved_for,
            'status' => $r->status, 'notes' => $r->notes,
        ];
    }
}
