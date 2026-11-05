<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/** A "session" is one login: a 30-minute access token + a 30-day refresh token sharing a session_key. */
class Sessions
{
    /** The session key of the token making this request (null for test/transient auth or sessions that pre-date keys). */
    public function currentKey(User $user): ?string
    {
        $t = $user->currentAccessToken();

        return $t instanceof PersonalAccessToken ? $t->session_key : null;
    }

    /** @return array{access: NewAccessToken, refresh: NewAccessToken, key: string} */
    public function issue(User $user, Request $request): array
    {
        $key = (string) Str::uuid();
        $access = $this->mint($user, 'access', ['*'], now()->addMinutes(30), $key, $request);
        $refresh = $this->mint($user, 'refresh', ['refresh'], now()->addDays(30), $key, $request);

        return ['access' => $access, 'refresh' => $refresh, 'key' => $key];
    }

    public function newAccess(User $user, PersonalAccessToken $refresh, Request $request): NewAccessToken
    {
        $key = $refresh->session_key ?: (string) Str::uuid();
        if (! $refresh->session_key) {
            $refresh->forceFill(['session_key' => $key])->save(); // adopt sessions created before this phase
        }

        return $this->mint($user, 'access', ['*'], now()->addMinutes(30), $key, $request);
    }

    private function mint(User $user, string $name, array $abilities, $expires, string $key, Request $request): NewAccessToken
    {
        $token = $user->createToken($name, $abilities, $expires);
        $token->accessToken->forceFill(['session_key' => $key, 'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 250, '')])->save();

        return $token;
    }

    /** @return list<array{id: string, ip: ?string, device: ?string, startedAt: mixed, lastActiveAt: mixed, current: bool}> */
    public function list(User $user, ?string $currentKey): array
    {
        $tokens = $user->tokens()->where('expires_at', '>', now())->orderBy('created_at')->get();
        $out = [];
        foreach ($tokens->groupBy(fn ($t) => $t->session_key ?: 'legacy-'.$t->id) as $key => $group) {
            if (! $group->contains(fn ($t) => $t->name === 'refresh')) {
                continue; // an access token alone is not a resumable session
            }
            $first = $group->first();
            $out[] = [
                'id' => $key, 'ip' => $first->ip_address, 'device' => $first->user_agent,
                'startedAt' => $first->created_at, 'lastActiveAt' => $group->max('last_used_at') ?? $first->created_at,
                'current' => $currentKey !== null && $key === $currentKey,
            ];
        }

        return array_reverse($out);
    }

    public function revoke(User $user, string $key): int
    {
        $q = $user->tokens();
        str_starts_with($key, 'legacy-') ? $q->where('id', substr($key, 7)) : $q->where('session_key', $key);

        return $q->delete();
    }

    /** Sign out everywhere except the session being used right now. */
    public function revokeOthers(User $user, ?string $currentKey): int
    {
        $q = $user->tokens();
        if ($currentKey) {
            $q->where(fn ($w) => $w->whereNull('session_key')->orWhere('session_key', '!=', $currentKey));
        }

        return $q->delete();
    }
}
