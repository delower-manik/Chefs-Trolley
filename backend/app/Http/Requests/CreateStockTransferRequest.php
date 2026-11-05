<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'uuid', 'exists:ingredients,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $ids = collect($this->input('items', []))->pluck('ingredient_id');
            if ($ids->duplicates()->isNotEmpty()) {
                $validator->errors()->add('items', 'The same ingredient was listed more than once in this transfer.');
            }
        });
    }
}
