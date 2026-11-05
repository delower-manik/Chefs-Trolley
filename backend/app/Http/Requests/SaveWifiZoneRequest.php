<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWifiZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100', Rule::unique('wifi_zones', 'name')
                ->where(fn ($q) => $q->where('branch_id', $this->attributes->get('branch_id')))
                ->ignore($this->route('wifiZone')?->id)],
            'ssid' => ['nullable', 'string', 'max:100'],
            'access_type' => ['nullable', 'in:open,voucher,time_limited'],
            'default_session_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
