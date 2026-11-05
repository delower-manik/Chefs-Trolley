<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveTaxRateRequest;
use App\Models\TaxRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxRateController extends Controller
{
    public function index(Request $request)
    {
        $rates = TaxRate::query()
            ->where('branch_id', $request->attributes->get('branch_id'))
            ->orderByDesc('is_default')->orderBy('name')
            ->get();

        return response()->json(['data' => $rates->map($this->serialize(...))]);
    }

    public function store(SaveTaxRateRequest $request)
    {
        $branchId = $this->requireBranch($request);
        if (! is_string($branchId)) {
            return $branchId;
        }

        $rate = DB::transaction(function () use ($request, $branchId) {
            if ($request->boolean('is_default')) {
                TaxRate::where('branch_id', $branchId)->update(['is_default' => false]);
            }

            return TaxRate::create([...$request->validated(), 'branch_id' => $branchId]);
        });

        $this->recordAudit($request, 'tax_rate.created', $rate, ['after' => $rate->only(['name', 'rate', 'is_inclusive', 'is_default'])]);

        return response()->json(['data' => $this->serialize($rate)], 201);
    }

    public function update(SaveTaxRateRequest $request, TaxRate $taxRate)
    {
        $before = $taxRate->only(['name', 'rate', 'is_inclusive', 'is_default', 'is_active']);

        DB::transaction(function () use ($request, $taxRate) {
            if ($request->boolean('is_default')) {
                TaxRate::where('branch_id', $taxRate->branch_id)->where('id', '!=', $taxRate->id)->update(['is_default' => false]);
            }
            $taxRate->update($request->validated());
        });

        $taxRate->refresh();
        $this->recordAudit($request, 'tax_rate.updated', $taxRate, ['before' => $before, 'after' => $taxRate->only(array_keys($before))]);

        return response()->json(['data' => $this->serialize($taxRate)]);
    }

    public function destroy(Request $request, TaxRate $taxRate)
    {
        // Items pointing at it fall back to the branch default (FK is nullOnDelete);
        // already-placed orders keep their snapshotted totals.
        $taxRate->delete();
        $this->recordAudit($request, 'tax_rate.deleted', $taxRate);

        return response()->noContent();
    }

    private function serialize(TaxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'branchId' => $rate->branch_id,
            'name' => $rate->name,
            'rate' => (float) $rate->rate,
            'isInclusive' => $rate->is_inclusive,
            'isDefault' => $rate->is_default,
            'isActive' => $rate->is_active,
        ];
    }
}
