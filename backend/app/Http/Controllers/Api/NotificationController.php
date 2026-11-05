<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveNotificationRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Services\Notify\Notifier;
use Illuminate\Http\Request;

/**
 * Announcement records. "Send" queues real deliveries (push / SMS / email) through the notification queue;
 * their outcome is tracked per recipient in notification_deliveries.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $notifications = Notification::query()->with('customer')
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $notifications->map($this->serialize(...))]);
    }

    public function store(SaveNotificationRequest $request)
    {
        $branchId = $request->attributes->get('branch_id');

        $notification = Notification::create([
            ...$request->validated(),
            'branch_id' => $branchId,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'notification.created',
            subjectType: Notification::class,
            subjectId: $notification->id,
            branchId: $notification->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($notification->load('customer'))], 201);
    }

    /**
     * POST /notifications/{notification}/send {channels?: [push|sms|email]}
     * Queues one delivery per recipient and channel (see notification_deliveries). Default channel is push only:
     * SMS costs money and there is no marketing-consent flag on customers yet, so SMS/email must be chosen explicitly.
     */
    public function send(Request $request, Notification $notification, Notifier $notifier)
    {
        $data = $request->validate([
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['in:push,sms,email'],
        ]);
        $channels = array_values(array_unique($data['channels'] ?? ['push']));

        if ($notification->status === 'sent') {
            return response()->json(['code' => 'already_sent', 'message' => 'This notification was already sent.'], 422);
        }

        $customers = Customer::query()->where('is_active', true)->where('marketing_opt_out', false)
            ->when($notification->audience === 'specific_customer', fn ($q) => $q->where('id', $notification->customer_id))
            ->when($notification->branch_id, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $notification->branch_id)->orWhereNull('branch_id')));

        $queued = ['push' => 0, 'sms' => 0, 'email' => 0];
        foreach ($customers->cursor() as $customer) {
            if (in_array('sms', $channels, true) && $customer->phone) {
                $queued['sms'] += (bool) $notifier->queue('sms', $customer->phone, $notification->title.': '.$notification->body, null, 'announcement', $notification->branch_id, $customer->id, Notification::class, $notification->id);
            }
            if (in_array('email', $channels, true) && $customer->email) {
                $queued['email'] += (bool) $notifier->queue('email', $customer->email, $notification->body, $notification->title, 'announcement', $notification->branch_id, $customer->id, Notification::class, $notification->id);
            }
            if (in_array('push', $channels, true)) {
                foreach (DeviceToken::where('owner_type', Customer::class)->where('owner_id', $customer->id)->pluck('token') as $token) {
                    $queued['push'] += (bool) $notifier->queue('push', $token, $notification->body, $notification->title, 'announcement', $notification->branch_id, $customer->id, Notification::class, $notification->id);
                }
            }
        }

        if (array_sum($queued) === 0) {
            return response()->json([
                'code' => 'no_recipients',
                'message' => 'Nobody can be reached on the chosen channel(s): no matching phone numbers, emails or registered app devices.',
            ], 422);
        }

        $notification->update(['status' => 'sent', 'sent_at' => now()]);

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'notification.sent',
            subjectType: Notification::class,
            subjectId: $notification->id,
            changes: ['channels' => $channels, 'queued' => $queued],
            branchId: $notification->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->serialize($notification->fresh('customer')), 'queued' => $queued]);
    }

    public function destroy(Request $request, Notification $notification)
    {
        $notification->delete();

        AuditLog::record(
            actorId: $request->user()->id,
            actorName: $request->user()->name,
            action: 'notification.deleted',
            subjectType: Notification::class,
            subjectId: $notification->id,
            branchId: $notification->branch_id,
            ipAddress: $request->ip(),
        );

        return response()->noContent();
    }

    private function serialize(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'branchId' => $notification->branch_id,
            'title' => $notification->title,
            'body' => $notification->body,
            'audience' => $notification->audience,
            'customerId' => $notification->customer_id,
            'customerName' => $notification->customer?->name,
            'status' => $notification->status,
            'sentAt' => $notification->sent_at,
            'createdAt' => $notification->created_at,
        ];
    }
}
