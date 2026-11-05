<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\NotBreached;
use App\Services\Security\Mfa;
use App\Services\Security\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** The signed-in person managing their own security: password, two-step sign-in, and where they're signed in. */
class AccountController extends Controller
{
    public function __construct(private Mfa $mfa, private Sessions $sessions) {}

    public function show(Request $request)
    {
        $u = $request->user();

        return response()->json(['data' => [
            'name' => $u->name, 'email' => $u->email,
            'mfaEnabled' => $this->mfa->enabled($u), 'recoveryCodesLeft' => $this->mfa->enabled($u) ? $this->mfa->recoveryCodesLeft($u) : 0,
            'passwordChangedAt' => $u->password_changed_at, 'lastLoginAt' => $u->last_login_at, 'lastLoginIp' => $u->last_login_ip,
        ]]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:150']]);
        $before = $request->user()->name;
        $request->user()->update($data);
        $this->recordAudit($request, 'account.profile_updated', $request->user(), ['before' => $before, 'after' => $data['name']]);

        return $this->show($request);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers(), new NotBreached],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is not right.']]);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['Choose a password different from your current one.']]);
        }

        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
        $revoked = $this->sessions->revokeOthers($user, $this->sessions->currentKey($user)); // anyone else holding a session is signed out
        $this->recordAudit($request, 'account.password_changed', $user, ['other_sessions_ended' => $revoked > 0]);

        return response()->json(['data' => ['changed' => true]]);
    }

    // ---- two-step sign-in ----

    public function mfaSetup(Request $request)
    {
        $user = $request->user();
        if ($this->mfa->enabled($user)) {
            return response()->json(['code' => 'mfa_already_enabled', 'message' => 'Two-step sign-in is already on. Turn it off first to enrol a new device.'], 422);
        }

        return response()->json(['data' => $this->mfa->begin($user)]);
    }

    public function mfaConfirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $codes = $this->mfa->confirm($request->user(), $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => ['That code is not valid. Check the clock on your phone and try the next code.']]);
        }
        $this->recordAudit($request, 'account.mfa_enabled', $request->user());

        return response()->json(['data' => ['recoveryCodes' => $codes]]);
    }

    public function mfaRecoveryCodes(Request $request)
    {
        $this->requireProof($request);
        $codes = $this->mfa->regenerateRecoveryCodes($request->user());
        $this->recordAudit($request, 'account.mfa_recovery_regenerated', $request->user());

        return response()->json(['data' => ['recoveryCodes' => $codes]]);
    }

    public function mfaDisable(Request $request)
    {
        $this->requireProof($request);
        $this->mfa->disable($request->user());
        $this->recordAudit($request, 'account.mfa_disabled', $request->user());

        return response()->json(['data' => ['mfaEnabled' => false]]);
    }

    /** Turning security down needs the password AND a current code, so a stolen open session can't do it. */
    private function requireProof(Request $request): void
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        if (! $this->mfa->enabled($user)) {
            throw ValidationException::withMessages(['code' => ['Two-step sign-in is not on.']]);
        }
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['Your password is not right.']]);
        }
        if (! $this->mfa->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }
    }

    // ---- sessions ----

    public function sessions(Request $request)
    {
        return response()->json(['data' => $this->sessions->list($request->user(), $this->sessions->currentKey($request->user()))]);
    }

    public function revokeSession(Request $request, string $key)
    {
        $this->sessions->revoke($request->user(), $key);
        $this->recordAudit($request, 'account.session_revoked', $request->user(), ['session' => $key]);

        return response()->noContent();
    }

    public function revokeOtherSessions(Request $request)
    {
        $n = $this->sessions->revokeOthers($request->user(), $this->sessions->currentKey($request->user()));
        $this->recordAudit($request, 'account.other_sessions_revoked', $request->user(), ['tokens' => $n]);

        return response()->noContent();
    }
}
