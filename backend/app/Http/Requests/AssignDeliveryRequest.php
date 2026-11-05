<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rider_id' => ['required', 'uuid', 'exists:riders,id'],
            'delivery_zone_id' => ['nullable', 'uuid', 'exists:delivery_zones,id'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
