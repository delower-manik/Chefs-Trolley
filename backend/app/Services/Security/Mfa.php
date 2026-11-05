<?php

namespace App\Services\Security;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Str;

class Mfa
{
    public function enabled(User $user): bool
    {
        return $user->mfa_secret !== null && $user->mfa_confirmed_at !== null;
    }

    /** Starts (or restarts, before it was confirmed) enrolment. */
    public function begin(User $user): array
    {
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri((string) config('security.mfa.issuer'), $user->email, $secret)];
    }

    /** Checks an authenticator code (not a recovery code) and records the step so it can't be used twice. */
    public function checkCode(User $user, string $code): bool
    {
        if (! $user->mfa_secret) {
            return false;
        }
        $step = Totp::matchStep($user->mfa_secret, $code);
        if ($step === null || ($user->mfa_last_step !== null && $step <= $user->mfa_last_step)) {
            return false;
        }
        $user->forceFill(['mfa_last_step' => $step])->save();

        return true;
    }

    /** Confirms enrolment; returns the one-time recovery codes (shown once, stored only as hashes). */
    public function confirm(User $user, string $code): ?array
    {
        if ($this->enabled($user) || ! $this->checkCode($user, $code)) {
            return null;
        }
        $user->forceFill(['mfa_confirmed_at' => now()])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < (int) config('security.mfa.recovery_codes', 10); $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }
        $user->forceFill(['mfa_recovery_codes' => json_encode(array_map($this->hash(...), $codes))])->save();

        return $codes;
    }

    /** Burns a recovery code if it is valid. */
    public function useRecoveryCode(User $user, string $input): bool
    {
        $input = strtolower(trim($input));
        $hashes = json_decode((string) $user->mfa_recovery_codes, true) ?: [];
        $h = $this->hash($input);
        foreach ($hashes as $i => $stored) {
            if (hash_equals($stored, $h)) {
                unset($hashes[$i]);
                $user->forceFill(['mfa_recovery_codes' => json_encode(array_values($hashes))])->save();

                return true;
            }
        }

        return false;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count(json_decode((string) $user->mfa_recovery_codes, true) ?: []);
    }

    public function disable(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
    }

    /** A TOTP code or a recovery code. */
    public function verify(User $user, string $code): bool
    {
        return preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $code) ? $this->checkCode($user, $code) : $this->useRecoveryCode($user, $code);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
