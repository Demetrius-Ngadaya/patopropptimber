<?php

namespace App\Support;

/**
 * Every permission in the system. Each module has a "view" permission that
 * controls access to its page(s); the other entries control buttons/actions.
 *
 * Run `php artisan mauzo:sync-permissions` (also run by the seeder) after
 * adding entries here.
 */
final class PermissionCatalog
{
    /**
     * @return array<string, array{label: string, permissions: array<string, array{0: string, 1: string}>}>
     *                                                                                                      module => [label, [action => [label, type(page|action)]]]
     */
    public static function modules(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'permissions' => [
                'view' => ['Open the dashboard', 'page'],
                'view_financials' => ['See revenue, profit and expense figures', 'action'],
                'view_all_branches' => ['Compare all branches', 'action'],
            ]],
            'pos' => ['label' => 'Point of sale', 'permissions' => [
                'access' => ['Open the POS / sell', 'page'],
                'discount' => ['Give discounts', 'action'],
                'change_price' => ['Change the selling price', 'action'],
                'sell_below_minimum' => ['Sell below the minimum price', 'action'],
                'credit_sale' => ['Sell on credit', 'action'],
                'hold' => ['Hold / resume sales', 'action'],
                'backdate' => ['Record a sale on an earlier date', 'action'],
                'wholesale' => ['Sell at wholesale prices', 'action'],
            ]],
            'credit' => ['label' => 'Credit sales (debts)', 'permissions' => [
                'view' => ['View credit sales and payment history', 'page'],
                'receive_payment' => ['Record full or partial debt payments', 'action'],
                'reschedule' => ['Change the expected repayment date', 'action'],
            ]],
            'savings' => ['label' => 'Customer savings', 'permissions' => [
                'view' => ['View savings accounts and history', 'page'],
                'open' => ['Open savings accounts', 'action'],
                'deposit' => ['Record deposits', 'action'],
                'withdraw' => ['Pay out (withdraw) savings', 'action'],
                'manage' => ['Freeze, reopen or close accounts', 'action'],
            ]],
            'sales' => ['label' => 'Sales', 'permissions' => [
                'view' => ['View sales', 'page'],
                'view_all_users' => ['See sales made by other users', 'action'],
                'view_profit' => ['See cost and profit', 'action'],
                'cancel' => ['Cancel (void) a sale', 'action'],
                'return' => ['Process returns', 'action'],
                'receive_payment' => ['Receive debt payments', 'action'],
                'reprint' => ['Reprint receipts', 'action'],
            ]],
            'online_payments' => ['label' => 'Online payments (mobile money by phone)', 'permissions' => [
                'request' => ['Ask the customer to pay by phone at the till', 'action'],
                'view' => ['See online payments and check their status', 'page'],
            ]],
            'orders' => ['label' => 'Customer orders', 'permissions' => [
                'view' => ['View orders', 'page'],
                'create' => ['Create orders for customers', 'action'],
                'update' => ['Edit orders', 'action'],
                'update_status' => ['Change order status', 'action'],
                'complete' => ['Complete an order (creates the sale)', 'action'],
                'cancel' => ['Cancel orders', 'action'],
            ]],
            'documents' => ['label' => 'Quotations, proformas & invoices', 'permissions' => [
                'view' => ['View quotations, proformas and invoices', 'page'],
                'create' => ['Create, edit, send and convert them', 'action'],
                'issue' => ['Issue invoices (the customer then owes the amount)', 'action'],
                'receive_payment' => ['Record payments on invoices and proformas', 'action'],
                'cancel' => ['Cancel them (an issued invoice cancels its sale)', 'action'],
            ]],
            'marketing' => ['label' => 'Customer insights & promotions', 'permissions' => [
                'insights' => ['See best, VIP, frequent and lost customers', 'page'],
                'promotions' => ['View promotions', 'page'],
                'manage' => ['Create and change promotions', 'action'],
                'notify' => ['Tell customers about promotions', 'action'],
                'apply' => ['Apply promotions at the till', 'action'],
            ]],
            'faqs' => ['label' => 'Online shop FAQs', 'permissions' => [
                'manage' => ['Manage the questions and answers shown in the online shop', 'page'],
            ]],
            'visitors' => ['label' => 'Website visitors', 'permissions' => [
                'view' => ['See website visitors (online now, daily, weekly...)', 'page'],
            ]],
            'mobile_app' => ['label' => 'Mobile app downloads', 'permissions' => [
                'manage' => ['Upload new versions of the mobile app for staff to download', 'page'],
            ]],
            'deliveries' => ['label' => 'Deliveries', 'permissions' => [
                'view' => ['See all deliveries of the branch', 'page'],
                'assign' => ['Give orders and sales to delivery people', 'action'],
                'own' => ['Deliver goods (delivery person): own deliveries, location, discount, chat', 'page'],
            ]],
            'customers' => ['label' => 'Customers', 'permissions' => [
                'view' => ['View customers', 'page'],
                'create' => ['Create customers', 'action'],
                'update' => ['Edit customer profiles', 'action'],
                'delete' => ['Delete customers', 'action'],
                'restore' => ['Restore deleted customers', 'action'],
                'manage_account' => ['Create / reset customer logins', 'action'],
                'set_credit' => ['Set credit limits', 'action'],
                'statement' => ['View statements', 'action'],
                'remind' => ['Send debt reminders', 'action'],
            ]],
            'suppliers' => ['label' => 'Suppliers', 'permissions' => [
                'view' => ['View suppliers', 'page'],
                'create' => ['Create suppliers', 'action'],
                'update' => ['Edit suppliers', 'action'],
                'delete' => ['Delete suppliers', 'action'],
                'restore' => ['Restore deleted suppliers', 'action'],
                'pay' => ['Record supplier payments', 'action'],
                'statement' => ['View statements', 'action'],
            ]],
            'products' => ['label' => 'Products', 'permissions' => [
                'view' => ['View products', 'page'],
                'create' => ['Create products', 'action'],
                'update' => ['Edit products', 'action'],
                'update_price' => ['Change prices', 'action'],
                'delete' => ['Delete products', 'action'],
                'restore' => ['Restore deleted products', 'action'],
                'import' => ['Import from Excel', 'action'],
                'export' => ['Export to Excel / PDF', 'action'],
                'view_cost' => ['See buying prices', 'action'],
                'print_labels' => ['Print barcode labels', 'action'],
            ]],
            'categories' => ['label' => 'Categories', 'permissions' => [
                'view' => ['View categories', 'page'],
                'create' => ['Create categories', 'action'],
                'update' => ['Edit categories', 'action'],
                'delete' => ['Delete categories', 'action'],
                'restore' => ['Restore categories', 'action'],
            ]],
            'units' => ['label' => 'Units of measure', 'permissions' => [
                'view' => ['View units', 'page'],
                'create' => ['Create units', 'action'],
                'update' => ['Edit units', 'action'],
                'delete' => ['Delete units', 'action'],
                'restore' => ['Restore units', 'action'],
            ]],
            'stock' => ['label' => 'Stock', 'permissions' => [
                'view' => ['View stock levels', 'page'],
                'alerts' => ['View stock & expiry alerts', 'page'],
                'movements' => ['View the stock ledger', 'page'],
                'adjust' => ['Adjust stock (count, damage, expiry)', 'action'],
                'opening' => ['Enter opening stock', 'action'],
            ]],
            'purchases' => ['label' => 'Purchases', 'permissions' => [
                'view' => ['View purchases', 'page'],
                'create' => ['Record purchases', 'action'],
                'credit' => ['Record purchases on credit', 'action'],
                'return' => ['Return goods to supplier', 'action'],
            ]],
            'purchase_plans' => ['label' => 'Purchase planning', 'permissions' => [
                'view' => ['View purchase plans', 'page'],
                'create' => ['Create and edit purchase plans', 'action'],
                'approve' => ['Make plans pending (approved / ordered)', 'action'],
                'receive' => ['Receive goods against a plan', 'action'],
                'cancel' => ['Cancel and close plans', 'action'],
            ]],
            'agency' => ['label' => 'Uwakala (mobile money & bank agency)', 'permissions' => [
                'view' => ['See uwakala days, floats and cash', 'page'],
                'record' => ['Open and close the day (floats and cash)', 'action'],
                'commissions' => ['Record commissions', 'action'],
                'manage' => ['Set up agency accounts and correct closed days', 'action'],
                'reports' => ['Uwakala and commission reports', 'page'],
            ]],
            'shifts' => ['label' => 'Shifts', 'permissions' => [
                'own' => ['My shifts: start, end, hand over, ask a colleague to cover', 'page'],
                'view' => ['See everyone\'s shifts', 'page'],
                'manage' => ['Plan and change shifts, reassign them', 'action'],
            ]],
            'messages' => ['label' => 'Staff messages & announcements', 'permissions' => [
                'send' => ['Send messages, reminders and announcements to staff', 'action'],
            ]],
            'transfers' => ['label' => 'Branch transfers', 'permissions' => [
                'view' => ['View transfers', 'page'],
                'create' => ['Send stock to another branch', 'action'],
                'receive' => ['Receive incoming transfers', 'action'],
                'cancel' => ['Cancel transfers in transit', 'action'],
                'mark_paid' => ['Mark sale / credit transfers as paid', 'action'],
            ]],
            'allocations' => ['label' => 'Seller allocations', 'permissions' => [
                'view' => ['View allocations', 'page'],
                'create' => ['Issue stock to sellers', 'action'],
                'reconcile' => ['Reconcile allocations', 'action'],
                'cancel' => ['Cancel allocations', 'action'],
                'view_own' => ['Sellers: view their own allocations', 'action'],
                'sell' => ['Sellers: sell from their own allocations (with receipts)', 'action'],
            ]],
            'expenses' => ['label' => 'Expenses', 'permissions' => [
                'view' => ['View expenses', 'page'],
                'create' => ['Record expenses', 'action'],
                'update' => ['Edit expenses', 'action'],
                'delete' => ['Delete expenses', 'action'],
                'restore' => ['Restore expenses', 'action'],
                'manage_categories' => ['Manage expense categories', 'action'],
            ]],
            'accounting' => ['label' => 'Accounting', 'permissions' => [
                'view' => ['View the chart of accounts and journals', 'page'],
                'manage_accounts' => ['Create and edit accounts', 'action'],
                'journal' => ['Post manual journal entries', 'action'],
                'reverse' => ['Reverse journal entries', 'action'],
                'statements' => ['Financial statements (P&L, balance sheet, cash flow, trial balance)', 'page'],
                'budgets' => ['Manage budgets', 'action'],
                'close_year' => ['Close and reopen financial years', 'action'],
                'fixed_assets' => ['Manage fixed assets and depreciation', 'action'],
                'reconcile' => ['Reconcile bank and mobile money accounts', 'action'],
            ]],
            'employees' => ['label' => 'Employees', 'permissions' => [
                'view' => ['View employees', 'page'],
                'create' => ['Add employees', 'action'],
                'update' => ['Edit employees', 'action'],
                'delete' => ['Delete employees', 'action'],
                'restore' => ['Restore employees', 'action'],
                'view_salary' => ['See salaries and bank details', 'action'],
                'documents' => ['Manage employee documents', 'action'],
                'terminate' => ['End employment (terminate / resign)', 'action'],
            ]],
            'departments' => ['label' => 'Departments & positions', 'permissions' => [
                'view' => ['View departments and positions', 'page'],
                'manage' => ['Create, edit and delete departments and positions', 'action'],
            ]],
            'attendance' => ['label' => 'Attendance', 'permissions' => [
                'view' => ['View attendance', 'page'],
                'record' => ['Record and correct attendance', 'action'],
            ]],
            'leave' => ['label' => 'Leave', 'permissions' => [
                'view' => ['View leave requests and balances', 'page'],
                'approve' => ['Approve or reject leave', 'action'],
                'manage' => ['Leave types, holidays and balances', 'action'],
            ]],
            'payroll' => ['label' => 'Payroll', 'permissions' => [
                'view' => ['View payroll and payslips', 'page'],
                'prepare' => ['Prepare payroll runs', 'action'],
                'approve' => ['Approve payroll (posts to accounts)', 'action'],
                'pay' => ['Pay salaries and remit statutory deductions', 'action'],
                'settings' => ['Salary components and tax rates', 'action'],
                'advances' => ['Manage salary advances', 'action'],
            ]],
            'hr' => ['label' => 'Self-service', 'permissions' => [
                'self_service' => ['Own profile, payslips, leave and attendance', 'page'],
            ]],
            'ai' => ['label' => 'Artificial intelligence', 'permissions' => [
                'assistant' => ['Ask the AI business assistant', 'page'],
                'insights' => ['AI insights on the dashboard', 'page'],
                'forecast' => ['Demand forecast and reorder suggestions', 'page'],
                'anomalies' => ['Fraud and anomaly alerts', 'page'],
                'credit_score' => ['Customer credit scores', 'page'],
                'generate' => ['Write product descriptions with AI', 'action'],
                'usage' => ['View AI usage and cost', 'page'],
            ]],
            'reports' => ['label' => 'Reports', 'permissions' => [
                'sales' => ['Sales reports', 'page'],
                'stock' => ['Stock & valuation reports', 'page'],
                'purchases' => ['Purchase & supplier reports', 'page'],
                'credit' => ['Debtors & credit reports', 'page'],
                'expenses' => ['Expense reports', 'page'],
                'profit' => ['Profit reports', 'page'],
                'performance' => ['Product & staff performance', 'page'],
                'transfers' => ['Transfer & allocation reports', 'page'],
                'export' => ['Export reports (Excel / PDF)', 'action'],
            ]],
            'branches' => ['label' => 'Branches', 'permissions' => [
                'view' => ['View branches', 'page'],
                'create' => ['Create branches', 'action'],
                'update' => ['Edit branches', 'action'],
                'delete' => ['Delete branches', 'action'],
                'restore' => ['Restore branches', 'action'],
            ]],
            'users' => ['label' => 'Staff users', 'permissions' => [
                'view' => ['View users', 'page'],
                'create' => ['Create users', 'action'],
                'update' => ['Edit users', 'action'],
                'delete' => ['Delete users', 'action'],
                'restore' => ['Restore users', 'action'],
                'reset_password' => ['Reset passwords / unlock', 'action'],
                'force_logout' => ['Force users to log out', 'action'],
                'permissions' => ['Give or remove permissions for one user', 'action'],
            ]],
            'roles' => ['label' => 'Roles & permissions', 'permissions' => [
                'view' => ['View roles', 'page'],
                'create' => ['Create roles', 'action'],
                'update' => ['Edit roles and their permissions', 'action'],
                'delete' => ['Delete roles', 'action'],
                'restore' => ['Restore roles', 'action'],
            ]],
            'login_slides' => ['label' => 'Login page pictures', 'permissions' => [
                'view' => ['View login page pictures', 'page'],
                'create' => ['Add pictures', 'action'],
                'update' => ['Edit and reorder pictures', 'action'],
                'delete' => ['Delete pictures', 'action'],
                'restore' => ['Restore deleted pictures', 'action'],
            ]],
            'settings' => ['label' => 'Settings', 'permissions' => [
                'view' => ['View settings', 'page'],
                'update' => ['Change settings', 'action'],
                'notifications' => ['Create automatic notifications (what, to whom, when, wording)', 'action'],
            ]],
            'audit' => ['label' => 'Audit logs', 'permissions' => [
                'activity' => ['Activity log (every action)', 'page'],
                'changes' => ['Data change log (before / after)', 'page'],
                'logins' => ['Login history', 'page'],
                'export' => ['Export audit logs', 'action'],
            ]],
            'sms' => ['label' => 'Messages (SMS, WhatsApp, email)', 'permissions' => [
                'send' => ['Send messages to customers and numbers', 'action'],
                'logs' => ['View the message log', 'page'],
            ]],
        ];
    }

    /** Flat list: [key => [module, action, label, type, sort]] */
    public static function flat(): array
    {
        $flat = [];
        $sort = 0;
        foreach (self::modules() as $module => $definition) {
            foreach ($definition['permissions'] as $action => [$label, $type]) {
                $flat["{$module}.{$action}"] = [
                    'module' => $module,
                    'action' => $action,
                    'label' => $label,
                    'type' => $type,
                    'sort_order' => $sort++,
                ];
            }
        }

        return $flat;
    }

    public static function keys(): array
    {
        return array_keys(self::flat());
    }

    /**
     * Default permission sets for the built-in roles (super admin bypasses checks).
     *
     * @return array<string, array<string>>
     */
    public static function defaultRolePermissions(): array
    {
        $all = self::keys();
        $without = fn (array $prefixes) => array_values(array_filter($all, function ($key) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return false;
                }
            }

            return true;
        }));

        return [
            'admin' => $without(['roles.delete', 'settings.update', 'audit.export', 'deliveries.own']),
            'branch-admin' => $without(['roles.', 'settings.', 'mobile_app.', 'branches.', 'users.delete', 'users.restore', 'dashboard.view_all_branches', 'audit.', 'deliveries.own']),
            'cashier' => [
                'dashboard.view', 'hr.self_service', 'pos.access', 'pos.hold', 'pos.credit_sale', 'sales.view', 'sales.reprint', 'sales.receive_payment', 'online_payments.request',
                'documents.view', 'documents.create', 'documents.receive_payment', 'marketing.apply', 'deliveries.view', 'deliveries.assign',
                'credit.view', 'credit.receive_payment', 'savings.view', 'savings.open', 'savings.deposit',
                'orders.view', 'orders.create', 'orders.update_status', 'orders.complete',
                'customers.view', 'customers.create', 'customers.update', 'products.view', 'stock.view', 'stock.alerts',
                'expenses.view', 'expenses.create', 'allocations.view_own', 'ai.assistant',
                'agency.view', 'agency.record', 'shifts.own',
            ],
            'storekeeper' => [
                'dashboard.view', 'hr.self_service', 'products.view', 'products.create', 'products.update', 'products.print_labels', 'products.view_cost',
                'categories.view', 'categories.create', 'units.view', 'stock.view', 'stock.alerts', 'stock.movements', 'stock.adjust', 'stock.opening',
                'purchases.view', 'purchases.create', 'purchases.return', 'suppliers.view', 'suppliers.create',
                'transfers.view', 'transfers.create', 'transfers.receive', 'reports.stock', 'ai.forecast', 'ai.assistant',
                'purchase_plans.view', 'purchase_plans.create', 'purchase_plans.receive', 'shifts.own',
            ],
            'accountant' => [
                'dashboard.view', 'hr.self_service', 'dashboard.view_financials', 'sales.view', 'sales.view_all_users', 'sales.view_profit', 'sales.receive_payment',
                'documents.view', 'documents.create', 'documents.issue', 'documents.receive_payment', 'documents.cancel', 'marketing.insights', 'marketing.promotions',
                'customers.view', 'customers.statement', 'customers.remind', 'suppliers.view', 'suppliers.pay', 'suppliers.statement',
                'purchases.view', 'expenses.view', 'expenses.create', 'expenses.update', 'expenses.manage_categories',
                'reports.sales', 'reports.stock', 'reports.purchases', 'reports.credit', 'reports.expenses', 'reports.profit', 'reports.performance', 'reports.transfers', 'reports.export',
                'transfers.view', 'transfers.mark_paid', 'allocations.view', 'allocations.reconcile',
                'credit.view', 'credit.receive_payment', 'savings.view', 'savings.deposit', 'savings.withdraw',
                'accounting.view', 'accounting.manage_accounts', 'accounting.journal', 'accounting.reverse', 'accounting.statements', 'accounting.budgets', 'accounting.fixed_assets', 'accounting.reconcile',
                'ai.assistant', 'ai.insights', 'ai.anomalies', 'ai.credit_score',
                'payroll.view', 'payroll.approve', 'payroll.pay', 'employees.view',
                'agency.view', 'agency.commissions', 'agency.reports', 'purchase_plans.view', 'purchase_plans.approve', 'purchase_plans.cancel', 'shifts.own', 'online_payments.view',
            ],
            'seller' => ['dashboard.view', 'allocations.view_own', 'allocations.sell', 'products.view', 'hr.self_service', 'shifts.own'],
            'hr-manager' => [
                'dashboard.view', 'hr.self_service', 'ai.assistant',
                'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.restore', 'employees.view_salary', 'employees.documents', 'employees.terminate',
                'departments.view', 'departments.manage', 'attendance.view', 'attendance.record',
                'leave.view', 'leave.approve', 'leave.manage', 'payroll.view', 'payroll.prepare', 'payroll.settings', 'payroll.advances',
                'shifts.own', 'shifts.view', 'shifts.manage', 'messages.send',
            ],
            'employee' => ['hr.self_service', 'shifts.own'],
            'delivery' => ['dashboard.view', 'hr.self_service', 'deliveries.own', 'shifts.own'],
        ];
    }
}
