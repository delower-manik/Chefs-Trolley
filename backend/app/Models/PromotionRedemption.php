<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionRedemption extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['promotion_id', 'order_id', 'customer_id', 'discount_amount', 'created_at'];

    protected function casts(): array
    {
        return ['discount_amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
