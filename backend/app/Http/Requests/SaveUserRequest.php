<?php

namespace App\Http\Requests;

use App\Rules\NotBreached;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind permission:users.manage; escalation rules live in AdminAccess
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        // The route parameter is the raw id (not a bound model) so the
        // controller can apply the actor's visibility scope when loading it.
        $userId = $this->route('user');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150'],
            // unique() also counts soft-deleted users, matching the database's
            // unique index, so a removed user's address can't be silently reused.
            'email' => [$isCreate ? 'required' : 'sometimes', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($userId)],
            // Passwords are set at creation or through the dedicated reset endpoint, never by a plain edit.
            'password' => $isCreate
                ? ['required', 'string', Password::min(10)->letters()->numbers(), new NotBreached]
                : ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => [$isCreate ? 'required' : 'sometimes', 'array', $isCreate ? 'min:1' : 'min:0', 'max:10'],
            'role_ids.*' => ['uuid', 'distinct', Rule::exists('roles', 'id')],
            'branch_ids' => [$isCreate ? 'required' : 'sometimes', 'array', $isCreate ? 'min:1' : 'min:0', 'max:50'],
            'branch_ids.*' => ['uuid', 'distinct', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'active_branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ];
    }
}
