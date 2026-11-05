<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:80', Rule::unique('tax_rates', 'name')
                ->where(fn ($q) => $q->where('branch_id', $this->attributes->get('branch_id'))->whereNull('deleted_at'))
                ->ignore($this->route('tax_rate')?->id)],
            'rate' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'is_inclusive' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
