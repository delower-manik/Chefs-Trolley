<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FloorPlan extends Model
{
    use HasUuids, SoftDeletes;

    protected $attributes = ['width' => 1000, 'height' => 600, 'sort_order' => 0, 'is_active' => true];

    protected $fillable = ['branch_id', 'name', 'width', 'height', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }
}
