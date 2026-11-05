<?php

namespace App\Http\Requests;

use App\Support\AdminAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $roleId = $this->route('role');

        return [
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'min:2', 'max:60',
                Rule::unique('roles', 'name')->ignore($roleId),
                // The Super Admin role is matched by name all over the codebase; no second one may be minted.
                Rule::notIn([AdminAccess::SUPER_ADMIN_ROLE]),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => [$isCreate ? 'required' : 'sometimes', 'array', 'max:200'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')],
        ];
    }
}
