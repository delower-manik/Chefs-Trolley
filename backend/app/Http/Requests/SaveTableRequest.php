<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTableRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A full object is required on create; the frontend's status-toggle
        // control sends only {status} on update, so those fields must not
        // be re-required there (this was a real bug -- toggling a table's
        // status previously 422'd because the update route shared this
        // same "everything required" rule set with create).
        $isCreate = $this->isMethod('POST');

        return [
            'branch_id' => array_merge([$isCreate ? 'required' : 'sometimes'], array_slice($this->branchIdRules(false), 1)),
            'label' => [
                $isCreate ? 'required' : 'sometimes', 'string', 'max:20',
                $this->uniqueInBranch('dining_tables', 'label', $this->targetBranchId($this->route('table')), $this->route('table')?->id),
            ],
            'capacity' => [$isCreate ? 'required' : 'sometimes', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', 'in:available,occupied,reserved,out_of_service'],
            'pos_x' => ['nullable', 'integer', 'min:0'],
            'pos_y' => ['nullable', 'integer', 'min:0'],
            'floor_plan_id' => ['nullable', 'uuid', Rule::exists('floor_plans', 'id')
                ->where(fn ($q) => $q->where('branch_id', $this->targetBranchId($this->route('table')))->whereNull('deleted_at'))],
            'width' => ['nullable', 'integer', 'min:30', 'max:600'],
            'height' => ['nullable', 'integer', 'min:30', 'max:600'],
            'shape' => ['nullable', 'in:rect,round'],
        ];
    }
}
