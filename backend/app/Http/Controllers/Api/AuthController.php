<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\LoginGuard;
use App\Services\Security\Mfa;
use App\Services\Security\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private Sessions $sessions, private Mfa $mfa, private LoginGuard $guard) {}

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', mb_strtolower($credentials['email']))->first();

        // Always run one password hash comparison, even for unknown emails, so
        // response time does not reveal which emails have accounts.
        $hash = $user?->password ?? Hash::make('timing-equalisation-placeholder');
        $passwordOk = Hash::check($credentials['password'], $hash);

        $locked = $user ? $this->guard->lockedMinutes($user) : 0;
        if ($locked > 0) {
            // Even the right password is refused while locked, so guessing can't continue through the lock.
            $this->audit($request, $user, 'auth.login_blocked_locked');
            throw ValidationException::withMessages(['email' => ["Too many failed attempts. Try again in {$locked} minute(s)."]]);
        }

        if (! $user || ! $user->is_active || ! $passwordOk) {
            // Only attempts against a real account are audited (the audit
            // table requires a subject); unknown emails are covered by the
            // throttle instead.
            if ($user) {
                $this->audit($request, $user, 'auth.login_failed');
                if ($this->guard->fail($user)) {
                    $this->audit($request, $user, 'auth.account_locked');
                }
            }

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($this->mfa->enabled($user)) {
            // Password is right but nothing is issued yet: hand back a short-lived challenge to answer with a code.
            $challenge = Str::random(48);
            Cache::put($this->challengeKey($challenge), ['user' => $user->id, 'tries' => 0], now()->addMinutes((int) config('security.mfa.challenge_minutes', 5)));

            return response()->json(['data' => ['mfaRequired' => true, 'challenge' => $challenge]]);
        }

        return $this->completeLogin($request, $user);
    }

    /** POST /auth/mfa — second step of sign-in: the challenge from /auth/login + an authenticator or recovery code. */
    public function verifyMfa(Request $request)
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:32']]);
        $key = $this->challengeKey($data['challenge']);
        $state = Cache::get($key);
        $user = $state ? User::find($state['user']) : null;

        if (! $state || ! $user || ! $user->is_active || ! $this->mfa->enabled($user)) {
            return response()->json(['code' => 'mfa_challenge_expired', 'message' => 'That sign-in attempt expired. Enter your password again.'], 401);
        }
        if ($this->guard->lockedMinutes($user) > 0) {
            Cache::forget($key);

            return response()->json(['code' => 'account_locked', 'message' => 'Too many failed attempts. Try again later.'], 423);
        }

        if (! $this->mfa->verify($user, $data['code'])) {
            $state['tries']++;
            $this->audit($request, $user, 'auth.mfa_failed');
            $locked = $this->guard->fail($user);
            if ($locked || $state['tries'] >= 5) {
                Cache::forget($key); // five wrong codes burn the challenge; the password step must be repeated
                $locked && $this->audit($request, $user, 'auth.account_locked');
            } else {
                Cache::put($key, $state, now()->addMinutes((int) config('security.mfa.challenge_minutes', 5)));
            }

            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        Cache::forget($key);

        return $this->completeLogin($request, $user, true);
    }

    private function completeLogin(Request $request, User $user, bool $withMfa = false)
    {
        $this->guard->succeed($user, $request->ip());
        $issued = $this->sessions->issue($user, $request);

        $this->audit($request, $user, 'auth.login', $withMfa ? ['mfa' => true] : null);

        return response()->json([
            'data' => [
                'user' => $this->serializeUser($user),
                'tokens' => [
                    'accessToken' => $issued['access']->plainTextToken,
                    'refreshToken' => $issued['refresh']->plainTextToken,
                    'expiresAt' => $issued['access']->accessToken->expires_at,
                ],
            ],
        ]);
    }

    public function refresh(Request $request)
    {
        $request->validate(['refreshToken' => ['required', 'string']]);

        $token = PersonalAccessToken::findToken($request->input('refreshToken'));

        if (! $token || ! $token->can('refresh') || $token->expires_at?->isPast()) {
            return response()->json(['code' => 'invalid_refresh_token', 'message' => 'Session expired. Please sign in again.'], 401);
        }

        $user = $token->tokenable;

        // A user who was deactivated or deleted since signing in must not be
        // able to mint fresh access tokens from an old refresh token.
        if (! $user || ! $user->is_active) {
            $token->delete();

            return response()->json(['code' => 'invalid_refresh_token', 'message' => 'Session expired. Please sign in again.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $accessToken = $this->sessions->newAccess($user, $token, $request);

        return response()->json([
            'data' => [
                'accessToken' => $accessToken->plainTextToken,
                'refreshToken' => $request->input('refreshToken'),
                'expiresAt' => $accessToken->accessToken->expires_at,
            ],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $this->serializeUser($request->user())]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $key = $this->sessions->currentKey($user);
        // Sign out the whole session (access + refresh), not just the access token, so the refresh token can't resurrect it.
        $key ? $this->sessions->revoke($user, $key) : $user->currentAccessToken()->delete();

        $this->audit($request, $user, 'auth.logout');

        return response()->noContent();
    }

    private function challengeKey(string $challenge): string
    {
        return 'mfa-challenge:'.hash('sha256', $challenge);
    }

    private function audit(Request $request, User $user, string $action, ?array $changes = null): void
    {
        AuditLog::record(
            actorId: in_array($action, ['auth.login', 'auth.logout'], true) ? $user->id : null,
            actorName: in_array($action, ['auth.login', 'auth.logout'], true) ? $user->name : null,
            action: $action,
            subjectType: User::class,
            subjectId: $user->id,
            branchId: $user->active_branch_id,
            changes: $changes,
            ipAddress: $request->ip(),
        );
    }

    private function serializeUser(User $user): array
    {
        $user->load(['roles.permissions', 'branches', 'activeBranch']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mfaEnabled' => $this->mfa->enabled($user),
            'roles' => $user->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'permissions' => $r->permissions->pluck('name'),
            ]),
            'permissions' => $user->permissionKeys(),
            'activeBranch' => $user->activeBranch ? [
                'id' => $user->activeBranch->id,
                'name' => $user->activeBranch->name,
                'code' => $user->activeBranch->code,
            ] : null,
            'branches' => $user->branches->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'code' => $b->code,
            ]),
        ];
    }
}
