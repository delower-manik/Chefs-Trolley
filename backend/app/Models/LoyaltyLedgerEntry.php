<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class LoyaltyLedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false; // only created_at is tracked, set explicitly

    protected $fillable = [
        'customer_id', 'type', 'points_change', 'balance_after',
        'reason', 'reference_type', 'reference_id', 'actor_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The single write path for every loyalty point movement -- same
     * ledger discipline as StockLedgerEntry, minus the per-warehouse
     * dimension (a customer only has the one balance). Row-locks the
     * customer before mutating `loyalty_points` so two orders for the same
     * customer landing at once can't desync the cached balance from the
     * ledger.
     */
    public static function record(
        Customer $customer,
        string $type,
        int $pointsChange,
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $actorId = null,
    ): self {
        return DB::transaction(function () use ($customer, $type, $pointsChange, $reason, $referenceType, $referenceId, $actorId) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->first();
            $newBalance = $locked->loyalty_points + $pointsChange;

            // forceFill: `loyalty_points` is deliberately NOT mass-assignable
            // (nothing a client sends may set a balance), so a plain
            // update() would silently discard the new value.
            $locked->forceFill(['loyalty_points' => $newBalance])->save();

            return self::create([
                'customer_id' => $customer->id,
                'type' => $type,
                'points_change' => $pointsChange,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actorId,
                'created_at' => now(),
            ]);
        });
    }
}
