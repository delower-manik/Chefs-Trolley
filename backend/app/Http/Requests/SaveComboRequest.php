<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $branchId = $this->attributes->get('branch_id');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('combos', 'name')
                ->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at'))
                ->ignore($this->route('combo')?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'items' => [$isCreate ? 'required' : 'sometimes', 'array', 'min:1', 'max:30'],
            // Only dishes of this branch (or chain-wide ones) can be bundled.
            'items.*.food_item_id' => ['required', 'uuid', 'distinct', Rule::exists('food_items', 'id')
                ->where(fn ($q) => $q->whereNull('deleted_at')->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }
}
