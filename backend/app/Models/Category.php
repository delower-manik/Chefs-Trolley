<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected $fillable = ['branch_id', 'name', 'slug', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function foodItems(): HasMany
    {
        return $this->hasMany(FoodItem::class);
    }
}
