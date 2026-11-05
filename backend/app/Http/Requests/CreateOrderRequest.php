<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'in:dine_in,takeaway,delivery,scheduled,pos,app_web'],
            'dining_table_id' => ['nullable', 'uuid', 'exists:dining_tables,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:200'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'delivery_zone_id' => ['nullable', 'uuid', 'exists:delivery_zones,id'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'hold' => ['nullable', 'boolean'], // true => save as draft ("hold") instead of sending to kitchen
            'items' => ['required', 'array', 'min:1'],
            // Each line is either a single dish or a combo (exactly one; enforced by OrderPricer).
            'items.*.food_item_id' => ['nullable', 'required_without:items.*.combo_id', 'uuid', 'exists:food_items,id'],
            'items.*.combo_id' => ['nullable', 'required_without:items.*.food_item_id', 'uuid', 'exists:combos,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['uuid', 'exists:modifiers,id'],
        ];
    }
}
