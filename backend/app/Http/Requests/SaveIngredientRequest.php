<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use Illuminate\Foundation\Http\FormRequest;

class SaveIngredientRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // branch_id is required on create but the edit form only ever
        // sends the editable fields (name/unit/reorder/cost) -- this
        // mirrors the same create-vs-update distinction fixed on
        // SaveTableRequest and SavePromotionRequest.
        $isCreate = $this->isMethod('POST');

        return [
            'branch_id' => array_merge([$isCreate ? 'required' : 'sometimes'], array_slice($this->branchIdRules(false), 1)),
            'unit_id' => [$isCreate ? 'required' : 'sometimes', 'uuid', 'exists:units,id'],
            'name' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:150',
                $this->uniqueInBranch('ingredients', 'name', $this->targetBranchId($this->route('ingredient')), $this->route('ingredient')?->id),
            ],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
