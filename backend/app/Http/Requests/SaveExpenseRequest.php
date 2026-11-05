<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'category_id' => [$isCreate ? 'required' : 'sometimes', 'uuid', 'exists:expense_categories,id'],
            'amount' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'expense_date' => [$isCreate ? 'required' : 'sometimes', 'date'],
        ];
    }
}
