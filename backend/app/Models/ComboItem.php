<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboItem extends Model
{
    use HasUuids;

    protected $attributes = ['quantity' => 1];

    protected $fillable = ['combo_id', 'food_item_id', 'quantity'];

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class);
    }
}
