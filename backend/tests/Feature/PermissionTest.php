<?php

namespace Tests\Feature;

use App\Models\Core\Branch;
use App\Models\Core\Permission;
use App\Models\Core\Role;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    public function test_cashier_cannot_open_user_management(): void
    {
        $this->actingAsStaff('cashier');

        $this->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_super_admin_can_open_everything(): void
    {
        $this->actingAsStaff('super-admin');

        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertOk();
        $this->getJson('/api/v1/admin/settings')->assertOk();
        $this->getJson('/api/v1/admin/audit/activity')->assertOk();
    }

    public function test_admin_can_create_a_role_and_grant_page_and_action_permissions(): void
    {
        $this->actingAsStaff('super-admin');

        $response = $this->postJson('/api/v1/admin/roles', [
            'name' => 'Butchery Supervisor',
            'permissions' => ['dashboard.view', 'products.view', 'stock.adjust'],
        ])->assertCreated();

        $role = Role::findOrFail($response->json('data.id'));
        $this->assertEqualsCanonicalizing(['dashboard.view', 'products.view', 'stock.adjust'], $role->permissions()->pluck('key')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.permissions_changed', 'auditable_id' => $role->id]);
    }

    public function test_permission_change_takes_effect_for_users_of_the_role(): void
    {
        $user = $this->actingAsStaff('cashier');
        $this->getJson('/api/v1/admin/expenses')->assertOk();

        $role = Role::where('slug', 'cashier')->first();
        $role->permissions()->detach(Permission::where('key', 'expenses.view')->value('id'));
        $user->flushPermissionCache();
        $user->unsetRelation('role');

        $this->getJson('/api/v1/admin/expenses')->assertForbidden();
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $this->actingAsStaff('super-admin');
        $role = Role::where('slug', 'cashier')->first();

        $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertStatus(422);
    }

    public function test_cashier_is_locked_to_their_branch(): void
    {
        $other = Branch::factory()->create();
        $this->actingAsStaff('cashier');

        $this->withHeader('X-Branch-Id', (string) $other->id)->getJson('/api/v1/admin/sales')->assertForbidden();
    }
}
