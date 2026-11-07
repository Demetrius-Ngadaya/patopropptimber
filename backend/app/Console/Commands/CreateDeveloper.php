<?php

namespace App\Console\Commands;

use App\Models\Core\Role;
use App\Models\Core\User;
use Database\Seeders\Core\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The only way to create the first Software Engineer: from the server console.
 * After that, a Software Engineer can add others from Staff users.
 */
class CreateDeveloper extends Command
{
    protected $signature = 'mauzo:create-developer {username} {--name=} {--email=} {--password= : Leave out to be asked (safer: not kept in shell history)}';

    protected $description = 'Create a Software Engineer account (hidden from all other users)';

    public function handle(): int
    {
        $role = Role::query()->where('slug', Role::DEVELOPER_SLUG)->first();
        if (! $role) {
            $this->callSilently('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
            $role = Role::query()->where('slug', Role::DEVELOPER_SLUG)->firstOrFail();
        }

        $data = [
            'username' => (string) $this->argument('username'),
            'name' => (string) ($this->option('name') ?: $this->ask('Full name', 'Software Engineer')),
            'email' => $this->option('email') ?: null,
            'password' => (string) ($this->option('password') ?: $this->secret('Password')),
        ];

        $validator = Validator::make($data, [
            'username' => ['required', 'string', 'min:3', 'max:60', 'alpha_dash', 'unique:users,username'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->forceCreate($data + [
            'type' => User::TYPE_STAFF,
            'role_id' => $role->id,
            'all_branches' => true,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->info("Software Engineer {$user->username} created. Only Software Engineers can see this account.");

        return self::SUCCESS;
    }
}
