<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:1000'],
            'audience' => ['required', 'in:all_customers,specific_customer'],
            'customer_id' => ['required_if:audience,specific_customer', 'nullable', 'uuid', 'exists:customers,id'],
        ];
    }
}
