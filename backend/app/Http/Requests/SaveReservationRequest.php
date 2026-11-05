<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use Illuminate\Foundation\Http\FormRequest;

class SaveReservationRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => $this->branchIdRules(true),
            'dining_table_id' => ['nullable', 'uuid', 'exists:dining_tables,id'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'reserved_for' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
