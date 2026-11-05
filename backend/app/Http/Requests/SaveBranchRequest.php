<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $branchId = $this->route('branch');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150'],
            'code' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'regex:/^[A-Z0-9][A-Z0-9-]{1,19}$/',
                Rule::unique('branches', 'code')->ignore($branchId),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => [$isCreate ? 'required' : 'sometimes', 'string', 'timezone:all'],
        ];
    }
}
