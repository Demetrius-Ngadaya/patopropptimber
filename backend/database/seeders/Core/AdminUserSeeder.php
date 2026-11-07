<?php

namespace Database\Seeders\Core;

use App\Models\Core\Branch;
use App\Models\Core\Role;
use App\Models\Core\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** First super admin. The password is printed once (or taken from SEED_ADMIN_PASSWORD). */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        if (! User::withTrashed()->where('username', 'admin')->exists()) {
            $password = env('SEED_ADMIN_PASSWORD') ?: Str::password(14, symbols: false);
            User::query()->forceCreate([
                'type' => User::TYPE_STAFF,
                'name' => 'System Administrator',
                'username' => 'admin',
                'email' => env('SEED_ADMIN_EMAIL'),
                'password' => $password,
                'role_id' => Role::query()->where('slug', 'super-admin')->value('id'),
                'branch_id' => $branch->id,
                'all_branches' => true,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $this->command?->warn('==============================================================');
            $this->command?->warn(" Admin login  -> username: admin   password: {$password}");
            $this->command?->warn(' You will be asked to change this password at first login.');
            $this->command?->warn('==============================================================');
        }

    }
}
