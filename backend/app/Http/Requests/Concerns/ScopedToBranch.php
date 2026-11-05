<?php

namespace App\Http\Requests\Concerns;

use App\Rules\AccessibleBranch;
use App\Support\AdminAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Shared branch handling for form requests that accept a `branch_id`.
 *
 *  - The id must be a branch the user can access (AccessibleBranch).
 *  - On create, anyone who is not a Super Admin gets their active branch
 *    when they send none, so a branch-level manager can never create a
 *    "chain-wide" (branch-less) record by omission.
 *  - uniqueInBranch() builds a "unique per branch" rule that matches the
 *    composite unique index, so a duplicate is a 422, not a database 500.
 */
trait ScopedToBranch
{
    protected function prepareForValidation(): void
    {
        if (! $this->isMethod('POST') || $this->filled('branch_id')) {
            return;
        }

        $active = $this->attributes->get('branch_id');
        $user = $this->user();

        if ($active && $user && ! AdminAccess::isSuperAdmin($user)) {
            $this->merge(['branch_id' => $active]);
        }
    }

    /** Rules for a `branch_id` body field. */
    protected function branchIdRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'uuid', 'exists:branches,id', new AccessibleBranch];
    }

    /** The branch a record will live in: the one in the body, else the existing record's. */
    protected function targetBranchId(?Model $existing = null): ?string
    {
        if ($this->has('branch_id')) {
            return $this->input('branch_id') ?: null;
        }

        return $existing?->branch_id;
    }

    protected function uniqueInBranch(string $table, string $column, ?string $branchId, ?string $ignoreId = null)
    {
        return Rule::unique($table, $column)
            ->where(fn ($query) => $branchId === null ? $query->whereNull('branch_id') : $query->where('branch_id', $branchId))
            ->ignore($ignoreId);
    }
}
