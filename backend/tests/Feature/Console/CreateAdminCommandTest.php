<?php

namespace Tests\Feature\Console;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    public function test_it_creates_a_super_admin_with_a_branch_and_a_working_login(): void
    {
        $this->artisan('chefs:create-admin', ['email' => 'Owner@Example.com', '--password' => 'Sup3rSecret123', '--name' => 'Owner'])
            ->assertExitCode(0);

        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Sup3rSecret123', $user->password));
        $this->assertTrue($user->roles()->where('name', 'Super Admin')->exists());
        $this->assertSame(1, Branch::count());
        $this->assertSame(Branch::first()->id, $user->active_branch_id);

        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'Sup3rSecret123'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['tokens' => ['accessToken', 'refreshToken']]]);
    }

    public function test_it_reuses_the_existing_branch_instead_of_creating_another(): void
    {
        $branch = $this->createBranch();

        $this->artisan('chefs:create-admin', ['email' => 'a@example.com', '--password' => 'Sup3rSecret123'])->assertExitCode(0);

        $this->assertSame(1, Branch::count());
        $this->assertTrue(User::where('email', 'a@example.com')->first()->branches->contains($branch));
    }

    public function test_it_refuses_a_weak_password_and_a_duplicate_email(): void
    {
        $this->artisan('chefs:create-admin', ['email' => 'a@example.com', '--password' => 'short'])->assertExitCode(1);
        $this->assertSame(0, User::count());

        $this->artisan('chefs:create-admin', ['email' => 'a@example.com', '--password' => 'Sup3rSecret123'])->assertExitCode(0);
        $this->artisan('chefs:create-admin', ['email' => 'A@example.com', '--password' => 'Sup3rSecret123'])->assertExitCode(1);
        $this->assertSame(1, User::count());
    }

    public function test_it_can_generate_a_password(): void
    {
        $this->artisan('chefs:create-admin', ['email' => 'gen@example.com', '--generate' => true])
            ->expectsOutputToContain('Generated password')
            ->assertExitCode(0);
    }

    public function test_it_fails_without_a_password_when_not_interactive(): void
    {
        $this->artisan('chefs:create-admin', ['email' => 'x@example.com', '--no-interaction' => true])->assertExitCode(1);
    }
}
