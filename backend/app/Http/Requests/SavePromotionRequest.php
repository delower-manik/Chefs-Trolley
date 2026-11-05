<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePromotionRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $promotionId = $this->route('promotion')?->id;
        $isCreate = $this->isMethod('POST');

        return [
            'branch_id' => $this->branchIdRules(false),
            'code' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:40', Rule::unique('promotions', 'code')->ignore($promotionId)],
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150'],
            'type' => [$isCreate ? 'required' : 'sometimes', 'in:percentage,fixed'],
            'value' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0.01'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('type') === 'percentage' && (float) $this->input('value', 0) > 100) {
                $validator->errors()->add('value', 'A percentage promotion cannot exceed 100.');
            }
        });
    }
}
