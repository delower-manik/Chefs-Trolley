<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;

        return [
            'branch_id' => $this->branchIdRules(false),
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('customers', 'phone')->ignore($customerId)],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'marketing_opt_out' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'in:en,bn'],
        ];
    }
}
