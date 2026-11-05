<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveFoodItemRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'branch_id' => $this->branchIdRules(false),
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Tax rate and station must belong to the active branch.
            'tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')
                ->where(fn ($q) => $q->where('branch_id', $this->attributes->get('branch_id'))->whereNull('deleted_at'))],
            'kitchen_station_id' => ['nullable', 'uuid', Rule::exists('kitchen_stations', 'id')
                ->where(fn ($q) => $q->where('branch_id', $this->attributes->get('branch_id'))->whereNull('deleted_at'))],
            'is_available' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'modifier_groups' => ['nullable', 'array'],
            'modifier_groups.*.name' => ['required_with:modifier_groups', 'string', 'max:80'],
            'modifier_groups.*.is_required' => ['boolean'],
            'modifier_groups.*.min_select' => ['integer', 'min:0'],
            'modifier_groups.*.max_select' => ['integer', 'min:1'],
            'modifier_groups.*.modifiers' => ['array'],
            'modifier_groups.*.modifiers.*.name' => ['required', 'string', 'max:80'],
            'modifier_groups.*.modifiers.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function slug(): string
    {
        return Str::slug($this->validated('name'));
    }
}
