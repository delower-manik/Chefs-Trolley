<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    private function makeUser(): User
    {
        $branch = $this->createBranch();
        $user = User::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@chefstrolley.test',
            'password' => Hash::make('correct-password'),
            'active_branch_id' => $branch->id,
        ]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    public function test_sixth_failed_attempt_in_a_minute_is_throttled(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ada@chefstrolley.test', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@chefstrolley.test', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonStructure(['retryAfter']);
    }

    public function test_throttle_blocks_even_the_correct_password_once_exhausted(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ada@chefstrolley.test', 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@chefstrolley.test', 'password' => 'correct-password'])
            ->assertStatus(429);
    }

    public function test_throttle_is_per_email_so_other_accounts_are_unaffected(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ada@chefstrolley.test', 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'someone-else@chefstrolley.test', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => 'ADA@ChefsTrolley.test', 'password' => 'correct-password'])
            ->assertStatus(200);
    }

    public function test_failed_login_against_real_account_is_audited_without_leaking_to_client(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@chefstrolley.test', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'subject_id' => $user->id]);
        $this->assertSame(0, AuditLog::where('action', 'auth.login')->count());
    }

    public function test_unknown_email_gets_the_same_response_as_wrong_password(): void
    {
        $this->makeUser();

        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@chefstrolley.test', 'password' => 'x']);
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'ada@chefstrolley.test', 'password' => 'x']);

        $unknown->assertStatus(422);
        $this->assertSame($wrong->json(), $unknown->json());
    }

    public function test_unknown_api_route_returns_json_not_found_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }
}
