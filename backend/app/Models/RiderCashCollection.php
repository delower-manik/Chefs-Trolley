<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderCashCollection extends Model
{
    use HasUuids;

    protected $fillable = ['branch_id', 'rider_id', 'order_id', 'delivery_assignment_id', 'payment_id', 'amount', 'collected_at', 'settled_at', 'settled_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'collected_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
