<?php

namespace Tests\Feature;

use App\Models\Core\Permission;
use App\Models\Core\Role;
use Tests\TestCase;

class PermissionUpgradeTest extends TestCase
{
    public function test_new_permissions_reach_built_in_roles_without_undoing_admin_changes(): void
    {
        $cashier = Role::query()->where('slug', 'cashier')->firstOrFail();
        // As before this release: the permission did not exist yet.
        Permission::query()->where('key', 'agency.record')->delete();
        // The admin had taken this one away from cashiers.
        $cashier->permissions()->detach(Permission::query()->where('key', 'expenses.create')->value('id'));

        $this->artisan('mauzo:sync-permissions')->assertSuccessful();

        $keys = $cashier->fresh()->permissions()->pluck('key');
        $this->assertTrue($keys->contains('agency.record'));
        $this->assertFalse($keys->contains('expenses.create'));
        $this->assertFalse(Role::query()->where('slug', 'storekeeper')->firstOrFail()->permissions()->where('key', 'agency.record')->exists());
    }
}
