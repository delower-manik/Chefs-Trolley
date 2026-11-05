<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A customer's saved delivery addresses. Always scoped to the signed-in customer; another customer's id is a 404. */
class AddressController extends Controller
{
    private const MAX = 10;

    public function index(Request $request)
    {
        return response()->json(['data' => $this->customer($request)->addresses()->orderByDesc('is_default')->orderBy('created_at')->get()->map(fn ($a) => $this->row($a))]);
    }

    public function store(Request $request)
    {
        $c = $this->customer($request);
        $data = $request->validate(['label' => ['required', 'string', 'min:1', 'max:40'], 'address' => ['required', 'string', 'min:5', 'max:255'], 'is_default' => ['sometimes', 'boolean']]);
        if ($c->addresses()->count() >= self::MAX) {
            return response()->json(['code' => 'too_many_addresses', 'message' => 'You can save up to '.self::MAX.' addresses. Remove one first.'], 422);
        }
        $a = DB::transaction(function () use ($c, $data) {
            $first = ! $c->addresses()->exists();
            $a = $c->addresses()->create([
                'label' => strip_tags($data['label']), 'address' => strip_tags($data['address']), 'is_default' => false,
            ]);
            if ($first || ! empty($data['is_default'])) {
                $this->makeDefault($c, $a);
            }

            return $a->fresh();
        });

        return response()->json(['data' => $this->row($a)], 201);
    }

    public function update(Request $request, string $address)
    {
        $c = $this->customer($request);
        $a = $c->addresses()->where('id', $address)->firstOrFail();
        $data = $request->validate(['label' => ['sometimes', 'string', 'min:1', 'max:40'], 'address' => ['sometimes', 'string', 'min:5', 'max:255'], 'is_default' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($c, $a, $data) {
            $a->update(array_map('strip_tags', array_intersect_key($data, ['label' => 1, 'address' => 1])));
            if (! empty($data['is_default'])) {
                $this->makeDefault($c, $a);
            }
        });

        return response()->json(['data' => $this->row($a->fresh())]);
    }

    public function destroy(Request $request, string $address)
    {
        $c = $this->customer($request);
        $a = $c->addresses()->where('id', $address)->firstOrFail();
        DB::transaction(function () use ($c, $a) {
            $wasDefault = $a->is_default;
            $a->delete();
            if ($wasDefault) {
                $next = $c->addresses()->orderBy('created_at')->first();
                $next ? $this->makeDefault($c, $next) : $c->update(['address' => null]);
            }
        });

        return response()->json(['data' => ['removed' => true]]);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    /** The default address is mirrored onto customers.address, which the till and the checkout pre-fill already use. */
    private function makeDefault(Customer $c, CustomerAddress $a): void
    {
        $c->addresses()->where('id', '!=', $a->id)->update(['is_default' => false]);
        $a->update(['is_default' => true]);
        $c->update(['address' => $a->address]);
    }

    private function row(CustomerAddress $a): array
    {
        return ['id' => $a->id, 'label' => $a->label, 'address' => $a->address, 'isDefault' => (bool) $a->is_default];
    }
}
