<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasUuids, Prunable;

    public function prunable()
    {
        return static::where('status', '!=', 'queued')->where('created_at', '<', now()->subDays(30));
    }

    protected $attributes = ['status' => 'queued', 'attempts' => 0];

    protected $fillable = [
        'webhook_id', 'event', 'event_id', 'payload', 'status', 'attempts', 'response_status', 'response_excerpt',
        'error', 'next_attempt_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
