<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntry extends Model
{
    use HasUuids;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'absent_days' => 0,
        'deductions' => 0,
        'bonuses' => 0,
    ];

    protected $fillable = [
        'payroll_period_id', 'employee_id', 'base_salary', 'absent_days', 'deductions', 'bonuses', 'net_pay',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'deductions' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
