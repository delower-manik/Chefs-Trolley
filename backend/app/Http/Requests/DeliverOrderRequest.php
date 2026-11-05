<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliverOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'proof_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
