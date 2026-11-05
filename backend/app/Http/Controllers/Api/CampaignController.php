<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Services\Marketing\CampaignSender;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __construct(private CampaignSender $sender) {}

    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        $rows = Campaign::query()->with('segment:id,name')
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($c) => $this->serialize($c))]);
    }

    public function show(Campaign $campaign)
    {
        $campaign->load('segment:id,name');
        $stats = NotificationDelivery::where('reference_type', Campaign::class)->where('reference_id', $campaign->id)
            ->selectRaw('channel, status, count(*) as n')->groupBy('channel', 'status')->get();

        return response()->json(['data' => $this->serialize($campaign) + [
            'deliveries' => $stats->groupBy('channel')->map(fn ($g) => $g->pluck('n', 'status')),
        ]]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($response = $this->segmentProblem($request, $data)) {
            return $response;
        }

        $campaign = Campaign::create($this->fill($data) + ['branch_id' => $request->attributes->get('branch_id'), 'created_by' => $request->user()->id]);
        $this->recordAudit($request, 'campaign.created', $campaign, ['name' => $campaign->name]);

        return response()->json(['data' => $this->serialize($campaign)], 201);
    }

    public function update(Request $request, Campaign $campaign)
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json(['code' => 'not_editable', 'message' => 'Only a draft or scheduled campaign can be edited.'], 422);
        }
        $data = $this->validated($request);
        if ($response = $this->segmentProblem($request, $data)) {
            return $response;
        }
        $campaign->update($this->fill($data));
        $this->recordAudit($request, 'campaign.updated', $campaign, ['name' => $campaign->name]);

        return response()->json(['data' => $this->serialize($campaign->fresh())]);
    }

    public function destroy(Request $request, Campaign $campaign)
    {
        if (! in_array($campaign->status, ['draft', 'cancelled'], true)) {
            return response()->json(['code' => 'not_deletable', 'message' => 'Only a draft or cancelled campaign can be deleted. Sent campaigns are kept as a record.'], 422);
        }
        $this->recordAudit($request, 'campaign.deleted', $campaign, ['name' => $campaign->name]);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted.']);
    }

    /** GET /campaigns/{campaign}/audience — who it would reach right now. */
    public function audience(Campaign $campaign)
    {
        $base = $this->sender->audience($campaign);
        $channels = $campaign->channels ?? [];

        return response()->json(['data' => [
            'total' => (clone $base)->count(),
            'sms' => in_array('sms', $channels, true) ? (clone $base)->whereNotNull('customers.phone')->where('customers.phone', '!=', '')->count() : 0,
            'email' => in_array('email', $channels, true) ? (clone $base)->whereNotNull('customers.email')->where('customers.email', '!=', '')->count() : 0,
            'push' => in_array('push', $channels, true) ? (clone $base)->whereIn('customers.id', DeviceToken::where('owner_type', Customer::class)->select('owner_id'))->count() : 0,
        ]]);
    }

    /** POST /campaigns/{campaign}/send {confirm_recipients} — send now. The caller states the audience size they saw. */
    public function send(Request $request, Campaign $campaign)
    {
        $data = $request->validate(['confirm_recipients' => ['required', 'integer', 'min:0']]);

        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json(['code' => 'already_sent', 'message' => 'This campaign has already been sent or cancelled.'], 422);
        }
        $total = $this->sender->audience($campaign)->count();
        if ($total === 0) {
            return response()->json(['code' => 'no_recipients', 'message' => 'Nobody matches this audience (customers who opted out are never messaged).'], 422);
        }
        if ($total > (int) config('notify.campaign_max_recipients', 5000)) {
            return response()->json(['code' => 'too_many_recipients', 'message' => "That is {$total} people, above the safety limit of ".config('notify.campaign_max_recipients', 5000).'. Narrow the segment.'], 422);
        }
        // The audience can change between looking and clicking; make the person confirm the number they saw.
        if ($data['confirm_recipients'] !== $total) {
            return response()->json(['code' => 'audience_changed', 'message' => "The audience is now {$total} people (you confirmed {$data['confirm_recipients']}). Check it and confirm again.", 'audience' => $total], 409);
        }

        $queued = $this->sender->send($campaign, $request->user()->id, $request->user()->name, $request->ip());
        if ($queued === null) {
            return response()->json(['code' => 'already_sent', 'message' => 'This campaign has already been sent or cancelled.'], 422);
        }

        return response()->json(['data' => $this->serialize($campaign->fresh()), 'queued' => $queued]);
    }

    /** POST /campaigns/{campaign}/cancel — stops a scheduled campaign before it goes out. */
    public function cancel(Request $request, Campaign $campaign)
    {
        $changed = Campaign::where('id', $campaign->id)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'cancelled']);
        if (! $changed) {
            return response()->json(['code' => 'not_cancellable', 'message' => 'Only a draft or scheduled campaign can be cancelled.'], 422);
        }
        $this->recordAudit($request, 'campaign.cancelled', $campaign, ['name' => $campaign->name]);

        return response()->json(['data' => $this->serialize($campaign->fresh())]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'segment_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:600'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:push,sms,email'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    private function fill(array $data): array
    {
        return [
            'name' => $data['name'], 'segment_id' => $data['segment_id'] ?? null, 'title' => $data['title'], 'body' => $data['body'],
            'channels' => array_values(array_unique($data['channels'])),
            'scheduled_at' => $data['scheduled_at'] ?? null, 'status' => ! empty($data['scheduled_at']) ? 'scheduled' : 'draft',
        ];
    }

    private function segmentProblem(Request $request, array $data)
    {
        if (empty($data['segment_id'])) {
            return null;
        }
        $branchId = $request->attributes->get('branch_id');
        $ok = CustomerSegment::where('id', $data['segment_id'])->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))->exists();

        return $ok ? null : response()->json(['code' => 'segment_not_found', 'message' => 'That segment does not exist in this branch.'], 422);
    }

    private function serialize(Campaign $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'segmentId' => $c->segment_id, 'segmentName' => $c->relationLoaded('segment') ? $c->segment?->name : null,
            'title' => $c->title, 'body' => $c->body, 'channels' => $c->channels, 'status' => $c->status,
            'scheduledAt' => $c->scheduled_at, 'sentAt' => $c->sent_at, 'audienceSize' => $c->audience_size,
            'queued' => $c->queued, 'createdAt' => $c->created_at,
        ];
    }
}
