<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class NotificationDelivery extends Model
{
    use HasUuids, Prunable;

    public function prunable()
    {
        return static::where('status', '!=', 'queued')->where('created_at', '<', now()->subDays(90));
    }

    protected $attributes = ['status' => 'queued', 'attempts' => 0, 'purpose' => 'custom'];

    protected $fillable = [
        'branch_id', 'channel', 'recipient', 'customer_id', 'subject', 'body', 'purpose', 'reference_type', 'reference_id',
        'status', 'provider', 'provider_message_id', 'attempts', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
