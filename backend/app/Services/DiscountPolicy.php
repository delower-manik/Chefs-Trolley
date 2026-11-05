<?php

namespace App\Services;

use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/** Who may give how much manual discount. Returns a 4xx response when refused, null when allowed. */
class DiscountPolicy
{
    public function check(User $user, int $amountMinor, int $baseMinor): ?JsonResponse
    {
        if (! $user->hasPermissionKey('discounts.apply') && ! $user->hasPermissionKey('discounts.override')) {
            return response()->json(['code' => 'discount_not_permitted', 'message' => 'You are not allowed to give discounts.'], 403);
        }

        if ($amountMinor > max(0, $baseMinor)) {
            return response()->json(['code' => 'discount_exceeds_total', 'message' => 'A discount cannot exceed the amount owed for the goods.'], 422);
        }

        if (! $user->hasPermissionKey('discounts.override')) {
            $cap = (float) config('pos.max_discount_percent', 20);
            if ($amountMinor > Money::percent($baseMinor, $cap)) {
                return response()->json([
                    'code' => 'discount_over_limit',
                    'message' => "Discounts above {$cap}% need a manager's override.",
                ], 422);
            }
        }

        return null;
    }
}
