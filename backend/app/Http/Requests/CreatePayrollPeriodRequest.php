<?php

namespace App\Http\Requests;

use App\Models\PayrollPeriod;
use Illuminate\Foundation\Http\FormRequest;

class CreatePayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start', function (string $attribute, mixed $value, \Closure $fail) {
                $exists = PayrollPeriod::query()
                    ->where('branch_id', $this->attributes->get('branch_id'))
                    ->whereDate('period_start', $this->input('period_start'))
                    ->whereDate('period_end', $value)
                    ->exists();

                if ($exists) {
                    $fail('A payroll period for these dates already exists.');
                }
            }],
        ];
    }
}
