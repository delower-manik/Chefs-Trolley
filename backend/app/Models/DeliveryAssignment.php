<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The delivery-specific state for one order -- who's carrying it, which
 * zone it's in, and the handoff timestamps. The order's own `status`
 * column remains the source of truth for the general lifecycle
 * (pending -> ... -> assigned -> picked_up -> delivered); this table only
 * adds what's genuinely delivery-only, including the `failed` outcome the
 * general Order lifecycle has no equivalent for.
 */
class DeliveryAssignment extends Model
{
    use HasUuids;

    public const FORWARD_TRANSITIONS = [
        'pending' => 'assigned',
        'assigned' => 'picked_up',
        'picked_up' => 'delivered',
    ];

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'order_id', 'branch_id', 'rider_id', 'delivery_zone_id', 'delivery_address',
        'status', 'assigned_at', 'picked_up_at', 'delivered_at', 'failed_at',
        'failure_reason', 'recipient_name', 'proof_note',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function canTransitionTo(string $target): bool
    {
        return (self::FORWARD_TRANSITIONS[$this->status] ?? null) === $target;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['delivered', 'failed'], true);
    }
}
