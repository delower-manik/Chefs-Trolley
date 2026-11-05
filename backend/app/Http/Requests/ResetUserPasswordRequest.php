<?php

namespace App\Http\Requests;

use App\Rules\NotBreached;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), new NotBreached],
        ];
    }
}
