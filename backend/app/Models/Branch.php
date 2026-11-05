<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'timezone' => 'Asia/Dhaka',
        'is_active' => true,
    ];

    protected $fillable = ['name', 'code', 'address', 'phone', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * Every branch needs somewhere for stock to live. Rather than forcing
     * a setup step before anyone can record a purchase, the first
     * warehouse a branch needs is created lazily and marked default --
     * branch managers can add more (and reassign the default) later via
     * WarehouseController.
     */
    public function defaultWarehouse(): Warehouse
    {
        return $this->warehouses()->firstOrCreate(
            ['branch_id' => $this->id, 'is_default' => true],
            ['name' => 'Main Warehouse']
        );
    }
}
