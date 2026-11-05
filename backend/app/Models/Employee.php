<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Mirrors the column defaults in the migration. Without this, a model
     * returned straight from create() lacks those attributes (they only exist
     * in the database row), so API responses built from it would show null
     * until the record is re-read.
     */
    protected $attributes = [
        'employment_type' => 'full_time',
        'monthly_salary' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'branch_id', 'user_id', 'name', 'phone', 'email', 'role_title',
        'employment_type', 'monthly_salary', 'hire_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_salary' => 'decimal:2',
            'hire_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    /** A simple daily rate for attendance-based payroll deductions: monthly salary / 30. */
    public function dailyRate(): float
    {
        return Money::toFloat($this->dailyRateMinor());
    }

    /** Daily rate in paisa, rounded half up. */
    public function dailyRateMinor(): int
    {
        return intdiv(Money::toMinor($this->monthly_salary) * 2 + 30, 60);
    }
}
