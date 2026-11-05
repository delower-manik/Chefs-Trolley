<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmployeeRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $employeeId = $this->route('employee')?->id;

        return [
            'branch_id' => $this->branchIdRules(false),
            'user_id' => ['nullable', 'uuid', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($employeeId)], // one login, one employee: the staff app resolves the person from it
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150'],
            'phone' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:30', Rule::unique('employees', 'phone')->ignore($employeeId)],
            'email' => ['nullable', 'email', 'max:150'],
            'role_title' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'hire_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
