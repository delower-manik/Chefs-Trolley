<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Adds another round of items to an open order (waiter tab). */
class AddOrderItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.food_item_id' => ['nullable', 'required_without:items.*.combo_id', 'uuid', 'exists:food_items,id'],
            'items.*.combo_id' => ['nullable', 'required_without:items.*.food_item_id', 'uuid', 'exists:combos,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['uuid', 'exists:modifiers,id'],
        ];
    }
}
