<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends Model
{
    use HasUuids;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'is_required' => false,
        'min_select' => 0,
        'max_select' => 1,
        'sort_order' => 0,
    ];

    protected $fillable = ['food_item_id', 'name', 'is_required', 'min_select', 'max_select', 'sort_order'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }
}
