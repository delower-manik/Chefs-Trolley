<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFloorPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:80', Rule::unique('floor_plans', 'name')
                ->where(fn ($q) => $q->where('branch_id', $this->attributes->get('branch_id'))->whereNull('deleted_at'))
                ->ignore($this->route('floor_plan')?->id)],
            'width' => ['nullable', 'integer', 'min:200', 'max:4000'],
            'height' => ['nullable', 'integer', 'min:200', 'max:4000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
