<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ingredients' => ['present', 'array'],
            'ingredients.*.ingredient_id' => ['required', 'uuid', 'exists:ingredients,id'],
            'ingredients.*.quantity_required' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
