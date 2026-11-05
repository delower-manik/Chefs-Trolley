<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id', 'opened_by', 'opening_float', 'opened_at', 'closed_by', 'closed_at',
        'expected_cash', 'counted_cash', 'variance', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:2', 'expected_cash' => 'decimal:2', 'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'shift_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'shift_id');
    }
}
