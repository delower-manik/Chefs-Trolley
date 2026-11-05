<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoyaltyAdjustmentRequest;
use App\Http\Requests\SaveCustomerRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LoyaltyLedgerEntry;
use App\Support\Like;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $query = Customer::query()
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = Like::contains((string) $request->query('search'));
                $q->where(fn ($q) => $q->whereRaw(Like::clause('name'), [$term])->orWhereRaw(Like::clause('phone'), [$term]));
            })
            ->orderBy('name');

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

    public function show(Customer $customer)
    {
        return response()->json(['data' => $this->serialize($customer)]);
    }

    /**
     * GET /customers/{customer}/orders — a customer's order history, so a
     * staff member can answer "what has this person ordered before"
     * without leaving the customer screen.
     */
    public function orders(Request $request, Customer $customer)
    {
        $orders = $customer->orders()->orderByDesc('created_at')->limit(20)->get();

        return response()->json([
            'data' => $orders->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'status' => $o->status,
                'grandTotal' => (float) $o->grand_total,
                'createdAt' => $o->created_at,
            ]),
        ]);
    }

    public function store(SaveCustomerRequest $request)
    {
        $customer = Customer::create($this->withConsent($request->validated()));

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'customer.created',
            subjectType: Customer::class,
            subjectId: $customer->id,
            branchId: $customer->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($customer)], 201);
    }

    public function update(SaveCustomerRequest $request, Customer $customer)
    {
        $before = $customer->only(['name', 'phone', 'is_active']);
        $customer->update($this->withConsent($request->validated(), $customer));

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'customer.updated',
            subjectType: Customer::class,
            subjectId: $customer->id,
            branchId: $customer->branch_id,
            changes: ['before' => $before, 'after' => $customer->fresh()->only(['name', 'phone', 'is_active'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($customer->fresh())]);
    }

    public function destroy(Request $request, Customer $customer)
    {
        $customer->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'customer.deleted',
            subjectType: Customer::class,
            subjectId: $customer->id,
            branchId: $customer->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    /**
     * POST /customers/{customer}/loyalty/adjust
     * A manual correction to a customer's point balance -- e.g. reversing
     * points from a cancelled order, or a goodwill grant. Always reasoned
     * and always audited, same discipline as a stock adjustment.
     */
    public function adjustLoyalty(LoyaltyAdjustmentRequest $request, Customer $customer)
    {
        $entry = LoyaltyLedgerEntry::record(
            customer: $customer,
            type: 'adjusted',
            pointsChange: $request->validated('points_change'),
            reason: $request->validated('reason'),
            actorId: $request->user()->id,
        );

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'customer.loyalty_adjusted',
            subjectType: Customer::class,
            subjectId: $customer->id,
            branchId: $customer->branch_id,
            changes: ['pointsChange' => $entry->points_change, 'balanceAfter' => $entry->balance_after, 'reason' => $entry->reason],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($customer->fresh())]);
    }

    /**
     * GET /customers/{customer}/loyalty/ledger
     */
    public function loyaltyLedger(Customer $customer)
    {
        $entries = $customer->loyaltyEntries()->orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'data' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'pointsChange' => $e->points_change,
                'balanceAfter' => $e->balance_after,
                'reason' => $e->reason,
                'createdAt' => $e->created_at,
            ]),
        ]);
    }

    /** Stamps when marketing consent changed, so there is a record of when someone opted out. */
    private function withConsent(array $data, ?Customer $existing = null): array
    {
        if (array_key_exists('marketing_opt_out', $data) && $data['marketing_opt_out'] !== null) {
            $changed = (bool) $data['marketing_opt_out'] !== (bool) ($existing?->marketing_opt_out ?? false);
            if ($changed) {
                $data['marketing_opt_out_at'] = $data['marketing_opt_out'] ? now() : null;
            }
        } else {
            unset($data['marketing_opt_out']);
        }

        return $data;
    }

    private function serialize(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'branchId' => $customer->branch_id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'notes' => $customer->notes,
            'loyaltyPoints' => $customer->loyalty_points,
            'isActive' => $customer->is_active,
            'marketingOptOut' => (bool) $customer->marketing_opt_out,
            'locale' => $customer->locale,
        ];
    }
}
