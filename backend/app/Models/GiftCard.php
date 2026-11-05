<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftCard extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id', 'code_hash', 'code_last4', 'initial_value', 'balance', 'status', 'expires_at', 'customer_id',
        'recipient_name', 'note', 'sold_method', 'sold_reference', 'created_by',
    ];

    protected function casts(): array
    {
        return ['initial_value' => 'decimal:2', 'balance' => 'decimal:2', 'expires_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GiftCardEntry::class)->orderBy('created_at')->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** active | void | expired | used (balance 0) — what a person should see. */
    public function displayStatus(): string
    {
        if ($this->status === 'void') {
            return 'void';
        }
        if ($this->isExpired()) {
            return 'expired';
        }

        return (float) $this->balance <= 0 ? 'used' : 'active';
    }
}
