<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'min_order_amount' => 0,
        'used_count' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'branch_id', 'code', 'name', 'type', 'value', 'min_order_amount', 'max_discount_amount',
        'usage_limit', 'usage_limit_per_customer', 'used_count', 'starts_at', 'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Promo codes are matched case-insensitively -- normalize to uppercase on write. */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtoupper(trim($value)),
        );
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function isWithinValidityWindow(): bool
    {
        $now = now();

        return (! $this->starts_at || $this->starts_at->lte($now))
            && (! $this->expires_at || $this->expires_at->gte($now));
    }

    public function hasRemainingUses(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    /**
     * Discount in integer paisa for a subtotal in paisa, capped so it never
     * exceeds the subtotal itself. Percentages round half up.
     */
    public function calculateDiscountMinor(int $subtotalMinor): int
    {
        $raw = $this->type === 'percentage'
            ? Money::percent($subtotalMinor, $this->value)
            : Money::toMinor($this->value);

        if ($this->max_discount_amount !== null) {
            $raw = min($raw, Money::toMinor($this->max_discount_amount));
        }

        return min($raw, $subtotalMinor);
    }

    /** BDT convenience wrapper around calculateDiscountMinor(). */
    public function calculateDiscount(float $subtotal): float
    {
        return Money::toFloat($this->calculateDiscountMinor(Money::toMinor($subtotal)));
    }
}
