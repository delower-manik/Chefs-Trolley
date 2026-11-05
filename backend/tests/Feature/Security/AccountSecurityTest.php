<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Rules\NotBreached;
use App\Services\Security\Mfa;
use App\Support\Totp;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    private function person(string $password = 'Correct-horse-9'): User
    {
        $branch = Branch::first() ?? Branch::create(['name' => 'Main', 'code' => 'MAIN']);
        $u = User::create(['name' => 'Rina', 'email' => 'rina'.uniqid().'@example.com', 'password' => Hash::make($password), 'active_branch_id' => $branch->id]);
        $u->branches()->attach($branch->id);

        return $u;
    }

    /** @return array{access: string, refresh: string} */
    private function login(User $u, string $password = 'Correct-horse-9'): array
    {
        $r = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => $password])->assertOk();

        return ['access' => $r->json('data.tokens.accessToken'), 'refresh' => $r->json('data.tokens.refreshToken')];
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards(); // each call authenticates from its own bearer token, not a cached user

        return $this->withToken($token);
    }

    private function enrol(User $u): array
    {
        $t = $this->login($u);
        $secret = $this->as($t['access'])->postJson('/api/v1/account/mfa/setup')->assertOk()->json('data.secret');
        $codes = $this->as($t['access'])->postJson('/api/v1/account/mfa/confirm', ['code' => Totp::code($secret, Totp::step())])->assertOk()->json('data.recoveryCodes');

        return ['secret' => $secret, 'codes' => $codes, 'tokens' => $t];
    }

    // ---- TOTP itself ----

    public function test_totp_matches_the_rfc_6238_test_vector(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::step(1111111109)));
        $this->assertSame(Totp::step(59), Totp::matchStep($secret, '287 082', 1, 59));
        $this->assertNull(Totp::matchStep($secret, '000000', 1, 59));
        $this->assertNull(Totp::matchStep($secret, 'abcdef', 1, 59));
    }

    // ---- two-step sign-in ----

    public function test_enrolling_requires_a_valid_code_and_gives_ten_one_time_recovery_codes(): void
    {
        $u = $this->person();
        $t = $this->login($u);
        $setup = $this->as($t['access'])->postJson('/api/v1/account/mfa/setup')->assertOk();
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.uri'));
        $this->assertFalse($u->fresh()->mfa_confirmed_at !== null);

        $this->as($t['access'])->postJson('/api/v1/account/mfa/confirm', ['code' => '000000'])->assertStatus(422);
        $res = $this->as($t['access'])->postJson('/api/v1/account/mfa/confirm', ['code' => Totp::code($setup->json('data.secret'), Totp::step())])->assertOk();

        $this->assertCount(10, $res->json('data.recoveryCodes'));
        $this->assertTrue($this->as($t['access'])->getJson('/api/v1/account')->json('data.mfaEnabled'));
        $this->as($t['access'])->postJson('/api/v1/account/mfa/setup')->assertStatus(422)->assertJsonPath('code', 'mfa_already_enabled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.mfa_enabled']);
        // The secret and recovery codes are stored encrypted, never as plain text.
        $raw = \DB::table('users')->where('id', $u->id)->first();
        $this->assertStringNotContainsString($setup->json('data.secret'), $raw->mfa_secret);
        $this->assertStringNotContainsString($res->json('data.recoveryCodes.0'), $raw->mfa_recovery_codes);
    }

    public function test_with_mfa_on_a_password_alone_gets_no_tokens(): void
    {
        $u = $this->person();
        $e = $this->enrol($u);
        $this->app['auth']->forgetGuards();

        $r = $this->withoutToken()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Correct-horse-9'])->assertOk();
        $this->assertTrue($r->json('data.mfaRequired'));
        $this->assertNull($r->json('data.tokens'));
        $challenge = $r->json('data.challenge');

        $this->postJson('/api/v1/auth/mfa', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422);
        $this->postJson('/api/v1/auth/mfa', ['challenge' => 'made-up', 'code' => '123456'])->assertStatus(401)->assertJsonPath('code', 'mfa_challenge_expired');

        // The next 30-second code (clock drift window) is valid; it is single-use.
        $code = Totp::code($e['secret'], Totp::step() + 1);
        $ok = $this->postJson('/api/v1/auth/mfa', ['challenge' => $challenge, 'code' => $code])->assertOk();
        $this->assertNotEmpty($ok->json('data.tokens.accessToken'));
        $this->assertTrue($ok->json('data.user.mfaEnabled'));

        $again = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Correct-horse-9'])->json('data.challenge');
        $this->postJson('/api/v1/auth/mfa', ['challenge' => $again, 'code' => $code])->assertStatus(422); // replay of the same code
        $this->postJson('/api/v1/auth/mfa', ['challenge' => $challenge, 'code' => $code])->assertStatus(401); // the used challenge is gone
    }

    public function test_a_recovery_code_works_once(): void
    {
        $u = $this->person();
        $e = $this->enrol($u);
        $this->app['auth']->forgetGuards();

        $c1 = $this->withoutToken()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Correct-horse-9'])->json('data.challenge');
        $this->postJson('/api/v1/auth/mfa', ['challenge' => $c1, 'code' => $e['codes'][0]])->assertOk();

        $c2 = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Correct-horse-9'])->json('data.challenge');
        $this->postJson('/api/v1/auth/mfa', ['challenge' => $c2, 'code' => $e['codes'][0]])->assertStatus(422);
        $this->postJson('/api/v1/auth/mfa', ['challenge' => $c2, 'code' => $e['codes'][1]])->assertOk();
        $this->assertSame(8, app(Mfa::class)->recoveryCodesLeft($u->fresh()));
    }

    public function test_five_wrong_codes_burn_the_challenge(): void
    {
        config(['security.lockout.attempts' => 50]);
        $u = $this->person();
        $e = $this->enrol($u);
        $this->app['auth']->forgetGuards();

        $c = $this->withoutToken()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Correct-horse-9'])->json('data.challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/mfa', ['challenge' => $c, 'code' => '111111'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/mfa', ['challenge' => $c, 'code' => Totp::code($e['secret'], Totp::step() + 1)])->assertStatus(401);
    }

    public function test_turning_mfa_off_needs_password_and_a_code_and_admin_can_reset_a_lost_device(): void
    {
        $u = $this->person();
        $e = $this->enrol($u);
        $tok = $e['tokens']['access'];

        $this->as($tok)->postJson('/api/v1/account/mfa/disable', ['password' => 'wrong', 'code' => $e['codes'][0]])->assertStatus(422);
        $this->as($tok)->postJson('/api/v1/account/mfa/disable', ['password' => 'Correct-horse-9', 'code' => '000000'])->assertStatus(422);
        $this->assertNotNull($u->fresh()->mfa_confirmed_at);

        // An administrator resets someone who lost their phone and codes.
        $this->app['auth']->forgetGuards();
        $this->withoutToken();
        $admin = $this->actingAsUserWithPermissions(['users.manage'], Branch::first());
        $role = Role::create(['name' => 'Cashier-x']);
        $u->roles()->attach($role->id);
        $u->branches()->syncWithoutDetaching($admin->branches->pluck('id'));
        $this->postJson("/api/v1/users/{$u->id}/mfa/reset")->assertOk();
        $this->assertNull($u->fresh()->mfa_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.mfa_reset', 'subject_id' => $u->id]);
    }

    // ---- lockout ----

    public function test_repeated_wrong_passwords_lock_the_account_even_against_the_right_password(): void
    {
        config(['security.lockout.attempts' => 3, 'security.lockout.minutes' => 15]);
        $u = $this->person();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'nope'])->assertStatus(422);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.account_locked', 'subject_id' => $u->id]);

        $r = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Correct-horse-9'])->assertStatus(422);
        $this->assertStringContainsString('Too many failed attempts', $r->json('errors.email.0'));

        $this->travel(16)->minutes();
        RateLimiter::clear('login:'.$u->email.'|127.0.0.1');
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Correct-horse-9'])->assertOk();
        $this->assertSame(0, $u->fresh()->failed_login_count);
    }

    // ---- password, profile, sessions ----

    public function test_changing_password_checks_the_current_one_enforces_strength_and_signs_out_other_devices(): void
    {
        $u = $this->person();
        $phone = $this->login($u);
        $laptop = $this->login($u);

        $url = '/api/v1/account/password';
        $this->as($laptop['access'])->putJson($url, ['current_password' => 'wrong', 'password' => 'Brand-new-pass-77', 'password_confirmation' => 'Brand-new-pass-77'])->assertStatus(422);
        $this->as($laptop['access'])->putJson($url, ['current_password' => 'Correct-horse-9', 'password' => 'short1', 'password_confirmation' => 'short1'])->assertStatus(422);
        $this->as($laptop['access'])->putJson($url, ['current_password' => 'Correct-horse-9', 'password' => 'Correct-horse-9', 'password_confirmation' => 'Correct-horse-9'])->assertStatus(422);
        $this->as($laptop['access'])->putJson($url, ['current_password' => 'Correct-horse-9', 'password' => 'Brand-new-pass-77', 'password_confirmation' => 'Brand-new-pass-77'])->assertOk();

        $this->as($laptop['access'])->getJson('/api/v1/auth/me')->assertOk();           // the device that changed it stays in
        $this->as($phone['access'])->getJson('/api/v1/auth/me')->assertStatus(401);     // every other session is gone
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $phone['refresh']])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'Brand-new-pass-77'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_changed']);
    }

    public function test_sessions_can_be_listed_and_revoked_and_logout_ends_the_whole_session(): void
    {
        $u = $this->person();
        $a = $this->login($u);
        $b = $this->login($u);

        $list = $this->as($a['access'])->getJson('/api/v1/account/sessions')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertCount(1, array_filter($list, fn ($s) => $s['current']));
        $other = collect($list)->firstWhere('current', false);

        $this->as($a['access'])->deleteJson('/api/v1/account/sessions/'.$other['id'])->assertNoContent();
        $this->as($b['access'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $b['refresh']])->assertStatus(401);

        $this->as($a['access'])->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $a['refresh']])->assertStatus(401); // refresh token can't resurrect it
    }

    public function test_sign_out_everywhere_else(): void
    {
        $u = $this->person();
        $a = $this->login($u);
        $b = $this->login($u);
        $c = $this->login($u);
        $this->as($a['access'])->deleteJson('/api/v1/account/sessions')->assertNoContent();
        $this->as($b['access'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->as($c['access'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->as($a['access'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_profile_update(): void
    {
        $u = $this->person();
        $t = $this->login($u);
        $this->as($t['access'])->putJson('/api/v1/account/profile', ['name' => 'Rina Akter'])->assertOk()->assertJsonPath('data.name', 'Rina Akter');
        $this->as($t['access'])->putJson('/api/v1/account/profile', ['name' => 'R'])->assertStatus(422);
    }

    // ---- hardening ----

    public function test_api_responses_carry_security_headers_and_are_not_cacheable(): void
    {
        $r = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x']);
        $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $r->headers->get('Content-Security-Policy'));
    }

    public function test_breached_passwords_are_rejected_and_an_outage_never_blocks(): void
    {
        config(['security.breach_check' => true]);
        $rule = new NotBreached;
        $sha = strtoupper(sha1('Password123!'));

        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(substr($sha, 5).":4821\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:2")]);
        $failed = false;
        $rule->validate('password', 'Password123!', function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/range/'.substr($sha, 0, 5))); // only the 5-char prefix leaves the server

        $failed = false;
        $rule->validate('password', 'a-never-seen-passphrase-xyz', function () use (&$failed) {
            $failed = true;
        });
        $this->assertFalse($failed);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response('boom', 503)]);
        $rule->validate('password', 'Password123!', function () use (&$failed) {
            $failed = true;
        });
        $this->assertFalse($failed);
    }

    public function test_every_api_route_except_the_known_public_ones_requires_authentication(): void
    {
        $public = ['api/v1/auth/login', 'api/v1/auth/mfa', 'api/v1/auth/refresh', 'api/v1/health', 'up'];
        $publicPrefixes = ['api/v1/payment-callbacks/', 'api/v1/marketing/unsubscribe/'];
        $open = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (in_array($uri, $public, true) || collect($publicPrefixes)->contains(fn ($p) => str_starts_with($uri, $p)) || ! str_starts_with($uri, 'api/')) {
                continue;
            }
            // Customer-website routes use their own token system; they must be an explicit public read/sign-in route or demand a customer token.
            if (str_starts_with($uri, 'api/v1/web/')) {
                $publicWeb = ['site', 'branches', 'branches/{branch}/menu', 'branches/{branch}/reviews', 'pages/{slug}', 'sitemap', 'realtime/config', 'auth/code', 'auth/verify'];
                $ok = in_array(substr($uri, strlen('api/v1/web/')), $publicWeb, true) || in_array('customer.auth', $route->gatherMiddleware(), true);
                if (! $ok || in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                    $open[] = implode('|', $route->methods()).' '.$uri;
                }

                continue;
            }
            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                $open[] = implode('|', $route->methods()).' '.$uri;
            }
        }
        $this->assertSame([], $open, 'Routes reachable without signing in: '.implode(', ', $open));
    }
}
