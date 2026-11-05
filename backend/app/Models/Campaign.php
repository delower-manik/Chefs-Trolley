<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Campaign extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id', 'segment_id', 'name', 'title', 'body', 'channels', 'status', 'scheduled_at', 'sent_at',
        'audience_size', 'queued', 'created_by',
    ];

    protected function casts(): array
    {
        return ['channels' => 'array', 'queued' => 'array', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CustomerSegment::class, 'segment_id');
    }
}
