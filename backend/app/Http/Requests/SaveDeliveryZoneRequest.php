<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100', Rule::unique('delivery_zones', 'name')
                ->where(fn ($q) => $q->where('branch_id', $this->attributes->get('branch_id')))
                ->ignore($this->route('delivery_zone')?->id)],
            'delivery_fee' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
