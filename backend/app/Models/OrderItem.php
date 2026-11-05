<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasUuids;

    public $timestamps = true;

    protected $attributes = ['kitchen_state' => 'new'];

    protected $fillable = ['order_id', 'food_item_id', 'kitchen_station_id', 'kitchen_state', 'combo_id', 'combo_name', 'name', 'quantity', 'unit_price', 'total', 'modifiers', 'notes'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'modifiers' => 'array',
        ];
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
