<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'url' => [$isCreate ? 'required' : 'sometimes', 'url', 'max:500'],
            'event' => [
                $isCreate ? 'required' : 'sometimes', 'string',
                'in:order.created,order.cancelled,order.delivered,review.created,customer.created',
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
