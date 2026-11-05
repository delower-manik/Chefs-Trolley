<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use HasUuids;

    protected $fillable = ['shift_id', 'branch_id', 'type', 'amount', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
