<?php

namespace App\Services\Security;

use App\Models\User;

/** Account lockout: N wrong passwords / MFA codes in a row lock the account for a while. */
class LoginGuard
{
    public function lockedMinutes(User $user): int
    {
        return $user->locked_until && $user->locked_until->isFuture() ? (int) max(1, ceil(now()->diffInSeconds($user->locked_until, false) / 60)) : 0;
    }

    /** @return bool true if this failure locked the account */
    public function fail(User $user): bool
    {
        $count = $user->failed_login_count + 1;
        $lock = $count >= (int) config('security.lockout.attempts', 5);
        $user->forceFill(['failed_login_count' => $lock ? 0 : $count, 'locked_until' => $lock ? now()->addMinutes((int) config('security.lockout.minutes', 15)) : $user->locked_until])->save();

        return $lock;
    }

    public function succeed(User $user, ?string $ip): void
    {
        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip])->save();
    }
}
