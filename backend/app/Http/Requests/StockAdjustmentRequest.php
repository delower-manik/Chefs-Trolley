<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ingredient_id' => ['required', 'uuid', 'exists:ingredients,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            // Positive = found extra stock (correction); negative = shrinkage/waste/damage.
            'quantity_change' => ['required', 'numeric', 'not_in:0'],
            'type' => ['required', 'in:adjustment,waste'],
            // Waste must always be explained; a plain adjustment should be too, but isn't forced.
            'reason' => ['required_if:type,waste', 'nullable', 'string', 'max:255'],
        ];
    }
}
