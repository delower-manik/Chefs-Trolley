<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GiftCardEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['gift_card_id', 'kind', 'amount', 'balance_after', 'order_id', 'payment_id', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }
}
