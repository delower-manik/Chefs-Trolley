<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\DeviceToken;
use App\Services\Marketing\SegmentQuery;
use Illuminate\Http\Request;

class CustomerSegmentController extends Controller
{
    public function __construct(private SegmentQuery $segments) {}

    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $rows = CustomerSegment::query()
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->orderBy('name')->get();

        return response()->json([
            'data' => $rows->map(fn ($s) => $this->serialize($s, $branchId)),
            'fields' => collect(SegmentQuery::FIELDS)->map(fn ($f, $key) => ['key' => $key, 'type' => $f[0], 'label' => $f[1]])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($response = $this->invalid($data)) {
            return $response;
        }

        $segment = CustomerSegment::create($data + ['branch_id' => $request->attributes->get('branch_id'), 'created_by' => $request->user()->id]);
        $this->recordAudit($request, 'segment.created', $segment, ['name' => $segment->name]);

        return response()->json(['data' => $this->serialize($segment, $segment->branch_id)], 201);
    }

    public function update(Request $request, CustomerSegment $segment)
    {
        $data = $this->validated($request);
        if ($response = $this->invalid($data)) {
            return $response;
        }
        $segment->update($data);
        $this->recordAudit($request, 'segment.updated', $segment, ['name' => $segment->name]);

        return response()->json(['data' => $this->serialize($segment, $segment->branch_id)]);
    }

    public function destroy(Request $request, CustomerSegment $segment)
    {
        $this->recordAudit($request, 'segment.deleted', $segment, ['name' => $segment->name]);
        $segment->delete(); // campaigns keep their record; their segment link is nulled by the FK

        return response()->json(['message' => 'Segment deleted.']);
    }

    /** POST /customer-segments/preview {match, rules} — how many people, and a sample, before saving anything. */
    public function preview(Request $request)
    {
        $data = $request->validate(SegmentQuery::validationRules());
        if ($response = $this->invalid($data)) {
            return $response;
        }

        return response()->json($this->previewFor($data['rules'], $data['match'], $request->attributes->get('branch_id')));
    }

    public function show(Request $request, CustomerSegment $segment)
    {
        return response()->json(['data' => $this->serialize($segment, $request->attributes->get('branch_id')) + [
            'sample' => $this->previewFor($segment->rules, $segment->match, $request->attributes->get('branch_id'))['sample'],
        ]]);
    }

    private function previewFor(array $rules, string $match, ?string $branchId): array
    {
        $matching = $this->segments->query($rules, $match, $branchId);
        $reachable = $this->segments->audience($rules, $match, $branchId);

        return [
            'matching' => (clone $matching)->count(),
            'optedOut' => (clone $matching)->where('customers.marketing_opt_out', true)->count(),
            'reachable' => [
                'sms' => (clone $reachable)->whereNotNull('customers.phone')->where('customers.phone', '!=', '')->count(),
                'email' => (clone $reachable)->whereNotNull('customers.email')->where('customers.email', '!=', '')->count(),
                'push' => (clone $reachable)->whereIn('customers.id', DeviceToken::where('owner_type', Customer::class)->select('owner_id'))->count(),
            ],
            'sample' => (clone $matching)->orderBy('customers.name')->limit(10)->get(['customers.id', 'customers.name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:255']] + SegmentQuery::validationRules());
    }

    private function invalid(array $data)
    {
        if ($message = SegmentQuery::problem($data['rules'])) {
            return response()->json(['code' => 'invalid_rule', 'message' => $message], 422);
        }

        return null;
    }

    private function serialize(CustomerSegment $s, ?string $branchId): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'match' => $s->match, 'rules' => $s->rules,
            'size' => $this->segments->query($s->rules, $s->match, $branchId)->count(), 'createdAt' => $s->created_at,
        ];
    }
}
