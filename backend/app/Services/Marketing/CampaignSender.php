<?php

namespace App\Services\Marketing;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Services\Notify\Notifier;
use Illuminate\Support\Facades\URL;

/**
 * Turns a campaign into queued notification deliveries (the Phase 14 pipeline: retries, delivery log).
 * Opted-out customers are never included. A campaign is claimed with an atomic status change, so two
 * scheduler runs (or a double click) can never send it twice.
 */
class CampaignSender
{
    public function __construct(private SegmentQuery $segments, private Notifier $notifier) {}

    /** The customers this campaign would reach, before channel filtering. */
    public function audience(Campaign $campaign)
    {
        $segment = $campaign->segment;

        return $this->segments->audience($segment?->rules ?? [], $segment?->match ?? 'all', $campaign->branch_id);
    }

    /**
     * Claim the campaign (draft/scheduled → sending) and queue it. Returns the queued counts, or null when
     * someone else already claimed it.
     *
     * @return array{push: int, sms: int, email: int}|null
     */
    public function send(Campaign $campaign, ?string $actorId = null, ?string $actorName = null, ?string $ip = null): ?array
    {
        $claimed = Campaign::where('id', $campaign->id)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'sending']);
        if (! $claimed) {
            return null;
        }
        $campaign->refresh();

        $channels = $campaign->channels ?? [];
        $queued = ['push' => 0, 'sms' => 0, 'email' => 0];
        $audience = 0;

        $this->audience($campaign)->orderBy('customers.id')->chunkById(200, function ($customers) use ($campaign, $channels, &$queued, &$audience) {
            foreach ($customers as $customer) {
                $audience++;
                $queued['sms'] += (int) (in_array('sms', $channels, true) && $this->queueSms($campaign, $customer));
                $queued['email'] += (int) (in_array('email', $channels, true) && $this->queueEmail($campaign, $customer));
                if (in_array('push', $channels, true)) {
                    foreach (DeviceToken::where('owner_type', Customer::class)->where('owner_id', $customer->id)->pluck('token') as $token) {
                        $queued['push'] += (int) (bool) $this->notifier->queue('push', $token, $campaign->body, $campaign->title, 'campaign', $campaign->branch_id, $customer->id, Campaign::class, $campaign->id);
                    }
                }
            }
        }, 'customers.id', 'id');

        $campaign->update(['status' => 'sent', 'sent_at' => now(), 'audience_size' => $audience, 'queued' => $queued]);

        AuditLog::record(
            actorId: $actorId, actorName: $actorName ?? 'Scheduler', action: 'campaign.sent', subjectType: Campaign::class,
            subjectId: $campaign->id, branchId: $campaign->branch_id, changes: ['name' => $campaign->name, 'audience' => $audience, 'queued' => $queued], ipAddress: $ip,
        );

        return $queued;
    }

    private function queueSms(Campaign $campaign, Customer $customer): bool
    {
        if (! $customer->phone) {
            return false;
        }

        return (bool) $this->notifier->queue('sms', $customer->phone, $campaign->title.': '.$campaign->body, null, 'campaign', $campaign->branch_id, $customer->id, Campaign::class, $campaign->id);
    }

    private function queueEmail(Campaign $campaign, Customer $customer): bool
    {
        if (! $customer->email) {
            return false;
        }
        // A one-click, signed opt-out link on every marketing email.
        $link = URL::signedRoute('marketing.unsubscribe', ['customer' => $customer->id]);
        $body = $campaign->body."\n\n—\nDon't want these emails? Unsubscribe: ".$link;

        return (bool) $this->notifier->queue('email', $customer->email, $body, $campaign->title, 'campaign', $campaign->branch_id, $customer->id, Campaign::class, $campaign->id);
    }
}
