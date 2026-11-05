<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePaymentGatewayConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['sometimes', 'boolean'],
            'config' => ['nullable', 'array'],
        ];
    }
}
