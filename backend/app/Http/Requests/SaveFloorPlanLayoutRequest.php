<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFloorPlanLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tables' => ['required', 'array', 'max:300'],
            'tables.*.id' => ['required', 'uuid', 'distinct'],
            'tables.*.pos_x' => ['required', 'integer', 'min:0', 'max:4000'],
            'tables.*.pos_y' => ['required', 'integer', 'min:0', 'max:4000'],
            'tables.*.width' => ['nullable', 'integer', 'min:30', 'max:600'],
            'tables.*.height' => ['nullable', 'integer', 'min:30', 'max:600'],
            'tables.*.shape' => ['nullable', 'in:rect,round'],
        ];
    }
}
