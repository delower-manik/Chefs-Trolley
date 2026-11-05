<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const CANCELLABLE_STATUSES = ['pending', 'confirmed'];

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'duration_minutes' => 90,
        'status' => 'pending',
    ];

    protected $fillable = [
        'branch_id', 'dining_table_id', 'customer_id', 'customer_name', 'customer_phone',
        'party_size', 'reserved_for', 'duration_minutes', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['reserved_for' => 'datetime'];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }

    /**
     * True if another live (non-cancelled/no-show) reservation already
     * occupies this table during the requested window. Two windows overlap
     * when one starts before the other ends, in both directions.
     */
    public static function hasConflict(
        ?string $tableId,
        \DateTimeInterface $reservedFor,
        int $durationMinutes,
        ?string $excludeId = null,
    ): bool {
        if (! $tableId) {
            return false; // unassigned reservations can't collide with a table
        }

        $newStart = Carbon::instance($reservedFor);
        $newEnd = $newStart->clone()->addMinutes($durationMinutes);

        return static::query()
            ->where('dining_table_id', $tableId)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->contains(function (self $existing) use ($newStart, $newEnd) {
                $existingStart = $existing->reserved_for;
                $existingEnd = $existingStart->clone()->addMinutes($existing->duration_minutes);

                return $newStart->lt($existingEnd) && $existingStart->lt($newEnd);
            });
    }
}
