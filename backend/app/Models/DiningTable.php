<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiningTable extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'dining_tables';

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'status' => 'available',
        'pos_x' => 0,
        'pos_y' => 0,
        'width' => 80,
        'height' => 80,
        'shape' => 'rect',
    ];

    protected $fillable = ['branch_id', 'label', 'capacity', 'status', 'pos_x', 'pos_y', 'floor_plan_id', 'width', 'height', 'shape'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Statuses that no longer keep a table busy. */
    public const CLOSED_ORDER_STATUSES = ['draft', 'cancelled', 'refunded', 'completed', 'delivered'];

    /** The table's current open tab, if any (held drafts do not count). */
    public function openOrder(): ?Order
    {
        return $this->orders()->whereNotIn('status', self::CLOSED_ORDER_STATUSES)->latest()->first();
    }

    /** Frees the table when its last open order has closed. Leaves reserved/out_of_service alone. */
    public function releaseIfIdle(): void
    {
        if ($this->status === 'occupied' && ! $this->openOrder()) {
            $this->update(['status' => 'available']);
        }
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
