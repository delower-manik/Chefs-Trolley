<?php

namespace App\Rules;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A branch id taken from a request body must be a branch the signed-in user
 * can actually work in. `exists:branches,id` only proves the branch exists;
 * without this, anyone holding (say) `menu.manage` in one outlet could write
 * records into any other outlet by sending its id.
 */
class AccessibleBranch implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = request()->user();

        if ($value === null || $value === '') {
            return;
        }

        if (! $user || ! AdminAccess::accessibleBranchIds($user)->contains($value)) {
            $fail('You do not have access to this branch.');
        }
    }
}
