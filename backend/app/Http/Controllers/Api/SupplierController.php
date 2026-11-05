<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSupplierRequest;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Support\Like;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $suppliers = Supplier::query()
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->when($request->filled('search'), fn ($q) => $q->whereRaw(Like::clause('name'), [Like::contains((string) $request->query('search'))]))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $suppliers->map($this->serialize(...))]);
    }

    public function store(SaveSupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'supplier.created',
            subjectType: Supplier::class,
            subjectId: $supplier->id,
            branchId: $supplier->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($supplier)], 201);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier)
    {
        $before = $supplier->only(['name', 'is_active']);
        $supplier->update($request->validated());

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'supplier.updated',
            subjectType: Supplier::class,
            subjectId: $supplier->id,
            branchId: $supplier->branch_id,
            changes: ['before' => $before, 'after' => $supplier->only(['name', 'is_active'])],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($supplier->fresh())]);
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        if ($supplier->purchases()->exists()) {
            return response()->json([
                'code' => 'supplier_has_purchases',
                'message' => 'This supplier has purchase history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }

        $supplier->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'supplier.deleted',
            subjectType: Supplier::class,
            subjectId: $supplier->id,
            branchId: $supplier->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'branchId' => $supplier->branch_id,
            'name' => $supplier->name,
            'contactPerson' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
            'isActive' => $supplier->is_active,
        ];
    }
}
