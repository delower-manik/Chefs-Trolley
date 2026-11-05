<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('warehouses', 'name')
                ->where(fn ($q) => $q->where('branch_id', $this->attributes->get('branch_id')))
                ->ignore($this->route('warehouse')?->id)],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
