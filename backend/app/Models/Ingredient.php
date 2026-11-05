<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'reorder_level' => 0,
        'cost_per_unit' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'branch_id', 'unit_id', 'name', 'reorder_level', 'cost_per_unit', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:3',
            'cost_per_unit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Ingredients whose stock across all warehouses is at or below the reorder
     * level. A correlated sub-select in WHERE rather than HAVING on a
     * `withSum` alias: HAVING needs a GROUP BY on SQLite/PostgreSQL, so the
     * alias form only happened to work on MySQL.
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw(
            'COALESCE((SELECT SUM(ingredient_stocks.quantity) FROM ingredient_stocks WHERE ingredient_stocks.ingredient_id = ingredients.id), 0) <= ingredients.reorder_level'
        );
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(IngredientStock::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }

    /**
     * Total stock across every warehouse in this ingredient's branch.
     * Prefers the `total_stock` sum eager-loaded via `withSum('stocks as
     * total_stock', 'quantity')` (see IngredientController@index) so
     * listing many ingredients doesn't run N extra queries; falls back to
     * a live sum when that wasn't eager-loaded.
     */
    public function totalStock(): float
    {
        if (array_key_exists('total_stock', $this->attributes)) {
            return (float) ($this->attributes['total_stock'] ?? 0);
        }

        return (float) $this->stocks()->sum('quantity');
    }

    public function stockAt(Warehouse $warehouse): float
    {
        return (float) ($this->stocks()->where('warehouse_id', $warehouse->id)->value('quantity') ?? 0);
    }

    public function isLowStock(): bool
    {
        return $this->totalStock() <= (float) $this->reorder_level;
    }
}
