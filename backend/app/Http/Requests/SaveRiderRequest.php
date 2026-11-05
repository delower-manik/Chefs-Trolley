<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRiderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $riderId = $this->route('rider')?->id;

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150'],
            'phone' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:30', Rule::unique('riders', 'phone')->ignore($riderId)],
            'vehicle_type' => ['nullable', 'in:bicycle,motorbike,car,on_foot'],
            'status' => ['nullable', 'in:available,busy,offline'],
            'is_active' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id', Rule::unique('riders', 'user_id')->ignore($riderId)],
        ];
    }
}
