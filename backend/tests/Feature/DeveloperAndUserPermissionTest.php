<?php

namespace Tests\Feature;

use App\Models\Core\Permission;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeveloperAndUserPermissionTest extends TestCase
{
    private function developer(): User
    {
        return $this->staff(Role::DEVELOPER_SLUG, null, ['all_branches' => true]);
    }

    // ------------------------------------------------------------ Software Engineer

    public function test_software_engineer_is_invisible_to_super_admins(): void
    {
        $dev = $this->developer();
        $devRole = Role::where('slug', Role::DEVELOPER_SLUG)->first();
        Sanctum::actingAs($this->staff('super-admin'));

        $ids = collect($this->getJson('/api/v1/admin/users?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($dev->id, $ids);
        $this->getJson("/api/v1/admin/users/{$dev->id}")->assertNotFound();
        $this->putJson("/api/v1/admin/users/{$dev->id}", ['name' => 'x', 'username' => 'xx1', 'role_id' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/admin/users/{$dev->id}")->assertNotFound();
        $this->postJson("/api/v1/admin/users/{$dev->id}/force-logout")->assertNotFound();

        $roleIds = collect($this->getJson('/api/v1/admin/roles')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($devRole->id, $roleIds);
        $this->getJson("/api/v1/admin/roles/{$devRole->id}")->assertNotFound();

        $staffIds = collect($this->getJson('/api/v1/admin/lookups?include=staff')->json('data.staff'))->pluck('id');
        $this->assertNotContains($dev->id, $staffIds);

        $this->getJson('/api/v1/admin/system/modules')->assertNotFound();
    }

    public function test_super_admin_cannot_create_a_software_engineer(): void
    {
        $devRole = Role::where('slug', Role::DEVELOPER_SLUG)->first();
        Sanctum::actingAs($this->staff('super-admin'));

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Sneaky', 'username' => 'sneaky', 'role_id' => $devRole->id, 'all_branches' => true,
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->assertDatabaseMissing('users', ['username' => 'sneaky']);
    }

    public function test_software_engineer_sees_and_manages_other_engineers(): void
    {
        $other = $this->developer();
        $devRole = Role::where('slug', Role::DEVELOPER_SLUG)->first();
        Sanctum::actingAs($this->developer());

        $this->getJson("/api/v1/admin/users/{$other->id}")->assertOk();
        $this->postJson('/api/v1/admin/users', [
            'name' => 'New Engineer', 'username' => 'eng2', 'role_id' => $devRole->id, 'all_branches' => true,
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
        ])->assertCreated();
        $this->assertTrue(User::where('username', 'eng2')->first()->isDeveloper());
    }

    public function test_engineer_actions_are_hidden_from_audit_logs(): void
    {
        $dev = $this->developer();
        Sanctum::actingAs($dev);
        $this->putJson('/api/v1/admin/system/modules', ['disabled' => ['payroll']])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.modules_changed', 'user_id' => $dev->id]);

        Sanctum::actingAs($this->staff('super-admin'));
        $events = collect($this->getJson('/api/v1/admin/audit/changes?per_page=100')->assertOk()->json('data'))->pluck('event');
        $this->assertNotContains('system.modules_changed', $events);
    }

    public function test_create_developer_command(): void
    {
        $code = Artisan::call('mauzo:create-developer', ['username' => 'eng1', '--name' => 'Eng One', '--password' => 'Secret123!']);

        $this->assertSame(0, $code);
        $this->assertTrue(User::where('username', 'eng1')->first()->isDeveloper());
    }

    // ------------------------------------------------------------ modules

    public function test_switched_off_module_is_gone_for_everyone_including_super_admin(): void
    {
        Sanctum::actingAs($this->developer());
        $this->putJson('/api/v1/admin/system/modules', ['disabled' => ['payroll', 'savings']])->assertOk();
        $this->putJson('/api/v1/admin/system/modules', ['disabled' => ['users']])->assertUnprocessable();

        $admin = $this->staff('super-admin');
        $this->assertFalse($admin->hasPermission('payroll.view'));
        $this->assertFalse($admin->hasPermission('savings.view'));
        $this->assertTrue($admin->hasPermission('employees.view'));

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/hr/payroll')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.disabled_modules', ['payroll', 'savings']);

        app(SettingsService::class)->update(['disabled_modules' => []]);
        $admin->flushPermissionCache();
        $this->assertTrue($admin->hasPermission('payroll.view'));
    }

    public function test_settings_screen_never_shows_the_developer_group(): void
    {
        Sanctum::actingAs($this->staff('super-admin'));
        $this->putJson('/api/v1/admin/settings', ['disabled_modules' => ['payroll']]);

        $this->assertSame([], app(SettingsService::class)->get('disabled_modules', []));
    }

    // ------------------------------------------------------------ per-user permissions

    public function test_permission_given_to_one_user_does_not_affect_others_with_the_role(): void
    {
        $alice = $this->staff('cashier');
        $bob = $this->staff('cashier');
        Sanctum::actingAs($this->staff('super-admin'));

        $this->putJson("/api/v1/admin/users/{$alice->id}/permissions", [
            'grants' => ['reports.profit'],
            'denies' => ['expenses.view'],
        ])->assertOk();

        $alice = $alice->fresh();
        $bob = $bob->fresh();
        $this->assertTrue($alice->hasPermission('reports.profit'));
        $this->assertFalse($alice->hasPermission('expenses.view'));
        $this->assertFalse($bob->hasPermission('reports.profit'));
        $this->assertTrue($bob->hasPermission('expenses.view'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.permissions_changed', 'auditable_id' => $alice->id]);

        $this->getJson("/api/v1/admin/users/{$alice->id}/permissions")->assertOk()
            ->assertJsonPath('data.grants', ['reports.profit'])
            ->assertJsonPath('data.denies', ['expenses.view']);

        Sanctum::actingAs($alice);
        $this->getJson('/api/v1/admin/expenses')->assertForbidden();
        Sanctum::actingAs($bob);
        $this->getJson('/api/v1/admin/expenses')->assertOk();
    }

    public function test_user_permission_guards(): void
    {
        $admin = $this->staff('admin');
        $admin->role->permissions()->detach(Permission::where('key', 'audit.export')->value('id'));
        $cashier = $this->staff('cashier');
        $super = $this->staff('super-admin');
        Sanctum::actingAs($admin);

        // Cannot hand out what you do not hold.
        $this->putJson("/api/v1/admin/users/{$cashier->id}/permissions", ['grants' => ['audit.export'], 'denies' => []])->assertForbidden();
        // Not your own, and not a super admin.
        $this->putJson("/api/v1/admin/users/{$admin->id}/permissions", ['grants' => [], 'denies' => []])->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$super->id}/permissions", ['grants' => [], 'denies' => []])->assertUnprocessable();
        // Both given and removed.
        $this->putJson("/api/v1/admin/users/{$cashier->id}/permissions", ['grants' => ['reports.profit'], 'denies' => ['reports.profit']])->assertUnprocessable();

        Sanctum::actingAs($cashier);
        $this->getJson("/api/v1/admin/users/{$admin->id}/permissions")->assertForbidden();
    }

    public function test_admin_chosen_colours_are_validated_and_public(): void
    {
        Sanctum::actingAs($this->staff('super-admin'));
        $this->putJson('/api/v1/admin/settings', ['theme_primary' => 'red'])->assertUnprocessable()->assertJsonValidationErrors('theme_primary');
        $this->putJson('/api/v1/admin/settings', ['theme_primary' => '#1d5fbf', 'theme_sidebar' => '#0f1e3d'])->assertOk();

        $this->getJson('/api/v1/public/settings')->assertOk()
            ->assertJsonPath('data.theme_primary', '#1d5fbf')
            ->assertJsonPath('data.theme_sidebar', '#0f1e3d')
            ->assertJsonMissingPath('data.disabled_modules');
    }
}
