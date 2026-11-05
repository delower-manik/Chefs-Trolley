<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Modifier extends Model
{
    use HasUuids;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'price' => 0,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $fillable = ['modifier_group_id', 'name', 'price', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
