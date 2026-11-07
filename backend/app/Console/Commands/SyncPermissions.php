<?php

namespace App\Console\Commands;

use App\Models\Core\Permission;
use App\Models\Core\Role;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'mauzo:sync-permissions';

    protected $description = 'Create / update permissions from the permission catalogue (safe to run any time)';

    public function handle(): int
    {
        $created = 0;
        $new = [];
        foreach (PermissionCatalog::flat() as $key => $definition) {
            $permission = Permission::query()->updateOrCreate(['key' => $key], $definition);
            if ($permission->wasRecentlyCreated) {
                $created++;
                $new[$key] = $permission->id;
            }
        }

        // A new feature reaches the built-in roles that have it by default. Only
        // permissions created just now are added, so an admin's choices stay as they are.
        if ($new) {
            foreach (PermissionCatalog::defaultRolePermissions() as $slug => $keys) {
                $role = Role::query()->where('slug', $slug)->where('is_super_admin', false)->first();
                $add = array_values(array_intersect_key($new, array_flip($keys)));
                if ($role && $add) {
                    $role->permissions()->syncWithoutDetaching($add);
                }
            }
        }

        $obsolete = Permission::query()->whereNotIn('key', PermissionCatalog::keys())->get();
        foreach ($obsolete as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        $this->info("Permissions synced: {$created} new, ".$obsolete->count().' removed, '.count(PermissionCatalog::keys()).' total.');

        return self::SUCCESS;
    }
}
