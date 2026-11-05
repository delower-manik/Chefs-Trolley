<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Lifecycle: draft -> pending -> confirmed -> accepted -> preparing ->
     * ready -> assigned -> picked_up -> delivered/completed -> cancelled/refunded.
     * See 01_CHEFS_TROLLEY_FULL_SYSTEM_ARCHITECTURE.md §7.
     */
    public const CANCELLABLE_STATUSES = [
        'draft', 'pending', 'confirmed', 'accepted', 'preparing',
    ];

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'subtotal' => 0,
        'tax_total' => 0,
        'tax_inclusive_total' => 0,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 0,
        'payment_state' => 'unpaid',
        'kitchen_state' => 'new',
        'status' => 'draft',
    ];

    protected $fillable = [
        'channel', 'delivery_phone', 'code', 'source', 'branch_id', 'customer_id', 'dining_table_id', 'subtotal', 'tax_total', 'tax_inclusive_total',
        'discount_total', 'delivery_fee', 'grand_total', 'payment_state',
        'kitchen_state', 'status', 'idempotency_key', 'created_by',
        // Set only by OrderController@cancel from server-side values. They
        // must be fillable: otherwise update() silently discards them and a
        // cancelled order loses its reason, timestamp and actor.
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
        'manual_discount_total', 'discount_reason', 'discount_by', 'stock_restocked_at',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected function casts(): array
    {
        return [
            'manual_discount_total' => 'decimal:2',
            'stock_restocked_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tax_inclusive_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function deliveryAssignment(): HasOne
    {
        return $this->hasOne(DeliveryAssignment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }

    /**
     * Simple sequential-looking human-readable order code. NOTE: count-based
     * generation is not safe under concurrent writes (two POS terminals
     * could race for the same number) -- fine for now, but before real
     * concurrent POS terminals go live (later in Phase 3) this should move
     * to a DB sequence or a per-branch counter row guarded by a lock.
     */
    public static function generateCode(): string
    {
        $next = static::withTrashed()->count() + 1001;

        return 'CT-'.$next;
    }
}
