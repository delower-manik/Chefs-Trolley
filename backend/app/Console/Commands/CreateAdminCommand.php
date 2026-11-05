<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first (or another) Super Admin from the command line. There is
 * deliberately no default account and no password committed anywhere: the
 * password is prompted for (hidden), passed with --password, or generated and
 * shown exactly once.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'chefs:create-admin
        {email : Login email for the new Super Admin}
        {--name=Administrator : Display name}
        {--password= : Password (omit to be prompted; use --generate for a random one)}
        {--generate : Generate a random password and print it once}
        {--branch-name=Main Branch : Name of the first branch, used only when no branch exists yet}
        {--branch-code=MAIN : Code of the first branch, used only when no branch exists yet}';

    protected $description = 'Create a Super Admin user (and the first branch, if none exists yet)';

    public function handle(): int
    {
        $generated = false;
        $password = (string) $this->option('password');

        if ($this->option('generate')) {
            $password = Str::password(16, symbols: false);
            $generated = true;
        } elseif ($password === '') {
            if (! $this->input->isInteractive()) {
                $this->error('No password given. Use --password=..., --generate, or run interactively.');

                return self::FAILURE;
            }
            $password = (string) $this->secret('Password (min 10 characters, letters and numbers)');
            if ($password !== (string) $this->secret('Confirm password')) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['email' => strtolower(trim($this->argument('email'))), 'password' => $password],
            [
                'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
                'password' => ['required', Password::min(10)->letters()->numbers()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($validator, $password) {
            if (! Role::where('name', 'Super Admin')->exists()) {
                $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
            }

            $branch = Branch::query()->orderBy('created_at')->first() ?? Branch::create([
                'name' => $this->option('branch-name'),
                'code' => strtoupper($this->option('branch-code')),
            ]);

            $user = User::create([
                'name' => $this->option('name'),
                'email' => $validator->validated()['email'],
                'password' => $password,
                'active_branch_id' => $branch->id,
            ]);
            $user->roles()->attach(Role::where('name', 'Super Admin')->value('id'));
            $user->branches()->attach($branch->id);

            AuditLog::record(
                actorId: null,
                actorName: 'console',
                action: 'user.created',
                subjectType: User::class,
                subjectId: $user->id,
                changes: ['email' => $user->email, 'role' => 'Super Admin', 'via' => 'chefs:create-admin'],
            );

            return $user;
        });

        $this->info("Super Admin created: {$user->email}");
        if ($generated) {
            $this->warn("Generated password (shown once, store it now): {$password}");
        }

        return self::SUCCESS;
    }
}
