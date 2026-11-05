<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id', 'branch_id', 'shift_id', 'kind', 'method', 'reference', 'amount', 'tip', 'tendered',
        'change_given', 'refunds_payment_id', 'reason', 'restocked', 'voided_at', 'voided_by', 'void_reason',
        'idempotency_key', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'tip' => 'decimal:2', 'tendered' => 'decimal:2', 'change_given' => 'decimal:2',
            'restocked' => 'boolean', 'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
