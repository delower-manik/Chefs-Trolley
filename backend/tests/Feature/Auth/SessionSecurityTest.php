<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    private function makeUser(array $overrides = []): User
    {
        $branch = $this->createBranch();
        $user = User::create(array_merge([
            'name' => 'Session Tester',
            'email' => uniqid().'@chefstrolley.test',
            'password' => Hash::make('Str0ngPassw0rd'),
            'active_branch_id' => $branch->id,
        ], $overrides));
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function bearer(string $uri, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($uri, ['Authorization' => 'Bearer '.$token]);
    }

    public function test_a_normal_access_token_works(): void
    {
        $token = $this->makeUser()->createToken('access', ['*'], now()->addMinutes(30))->plainTextToken;

        $this->bearer('/api/v1/auth/me', $token)->assertStatus(200);
    }

    public function test_a_refresh_token_cannot_be_used_to_call_the_api(): void
    {
        $token = $this->makeUser()->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;

        $this->bearer('/api/v1/auth/me', $token)
            ->assertStatus(401)->assertJsonPath('code', 'invalid_token_type');
    }

    public function test_a_deactivated_account_is_locked_out_even_with_a_valid_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('access', ['*'], now()->addMinutes(30))->plainTextToken;
        $user->update(['is_active' => false]);

        $this->bearer('/api/v1/auth/me', $token)
            ->assertStatus(401)->assertJsonPath('code', 'account_disabled');
    }

    public function test_a_deactivated_account_cannot_refresh_its_session(): void
    {
        $user = $this->makeUser();
        $refresh = $user->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;
        $user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refresh])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_refresh_token');
        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_an_active_account_can_still_refresh(): void
    {
        $refresh = $this->makeUser()->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;

        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $refresh])
            ->assertStatus(200)->assertJsonStructure(['data' => ['accessToken', 'refreshToken', 'expiresAt']]);
    }
}
