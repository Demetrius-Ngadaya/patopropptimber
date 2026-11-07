<?php

namespace Database\Seeders\Core;

use App\Models\Core\Permission;
use App\Models\Core\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/** Permissions from the catalogue and the built-in roles. Never overwrites an admin's changes. */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('mauzo:sync-permissions');

        $roles = [
            'super-admin' => ['Super Admin', 'Full access to everything, including roles and settings.', true, true, true],
            'admin' => ['Admin', 'Manages the business across all branches.', true, false, true],
            'branch-admin' => ['Branch Admin', 'Manages one branch: staff, stock, sales and reports.', true, false, false],
            'cashier' => ['Cashier', 'Sells at the till, takes orders and debt payments.', true, false, false],
            'storekeeper' => ['Storekeeper', 'Receives purchases, manages stock and transfers.', true, false, false],
            'accountant' => ['Accountant', 'Payments, expenses and financial reports.', true, false, true],
            'seller' => ['Seller', 'Field seller who receives stock allocations.', true, false, false],
            'hr-manager' => ['HR Manager', 'Employees, attendance, leave and payroll.', true, false, true],
            'employee' => ['Employee', 'Self-service: own payslips, leave requests and attendance.', true, false, false],
            'delivery' => ['Delivery person', 'Delivers orders: sees own deliveries, shares location, chats with the customer.', true, false, false],
        ];

        $defaults = PermissionCatalog::defaultRolePermissions();
        foreach ($roles as $slug => [$name, $description, $system, $super, $allBranches]) {
            $role = Role::withTrashed()->firstOrNew(['slug' => $slug]);
            $isNew = ! $role->exists;
            $role->forceFill([
                'name' => $role->name ?: $name,
                'description' => $role->description ?: $description,
                'is_system' => $system,
                'is_super_admin' => $super,
                'all_branches' => $isNew ? $allBranches : $role->all_branches,
            ])->save();

            // Only give default permissions to roles created now; never overwrite an admin's changes.
            if ($isNew && ! $super && isset($defaults[$slug])) {
                $role->permissions()->sync(Permission::query()->whereIn('key', $defaults[$slug])->pluck('id'));
            }
        }

        // Software Engineer: hidden from every other user, super admins included.
        // A reserved slug, so a business role that happens to be called "software-engineer" is never taken over.
        Role::withTrashed()->firstOrNew(['slug' => Role::DEVELOPER_SLUG])->forceFill([
            'name' => 'Software Engineer',
            'description' => 'Sets up the system: which modules are switched on. Invisible to all other users.',
            'is_system' => true,
            'is_super_admin' => true,
            'is_developer' => true,
            'all_branches' => true,
            'deleted_at' => null,
        ])->save();
    }
}
