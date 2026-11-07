<?php

namespace App\Support;

use App\Models\Agency\AgencyAccount;
use App\Models\Agency\AgencyCommission;
use App\Models\Agency\AgencyDay;
use App\Models\Ai\AiConversation;
use App\Models\Catalog;
use App\Models\Core;
use App\Models\Crm;
use App\Models\Delivery\Delivery;
use App\Models\Finance;
use App\Models\Finance\BankReconciliation;
use App\Models\Finance\FixedAsset;
use App\Models\Hr\Attendance;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeDocument;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\Position;
use App\Models\Hr\SalaryAdvance;
use App\Models\Hr\SalaryComponent;
use App\Models\Inventory;
use App\Models\Marketing\Promotion;
use App\Models\Payments;
use App\Models\Purchasing;
use App\Models\Sales;
use App\Models\Shifts\Shift;

/**
 * Short, stable names stored in polymorphic columns (audit logs, journal
 * sources, stock movement references). Never rename an existing key.
 */
final class MorphMap
{
    public static function map(): array
    {
        return [
            // Core
            'user' => Core\User::class,
            'branch' => Core\Branch::class,
            'role' => Core\Role::class,
            'setting' => Core\Setting::class,
            'login_slide' => Core\LoginSlide::class,
            // Catalog
            'category' => Catalog\Category::class,
            'unit' => Catalog\Unit::class,
            'product' => Catalog\Product::class,
            'product_unit' => Catalog\ProductUnit::class,
            // Inventory
            'stock_batch' => Inventory\StockBatch::class,
            'stock_adjustment' => Inventory\StockAdjustment::class,
            'transfer' => Inventory\Transfer::class,
            'allocation' => Inventory\Allocation::class,
            // CRM
            'customer' => Crm\Customer::class,
            'savings_account' => Crm\SavingsAccount::class,
            'savings_transaction' => Crm\SavingsTransaction::class,
            // Sales
            'sale' => Sales\Sale::class,
            'payment' => Sales\Payment::class,
            'sale_return' => Sales\SaleReturn::class,
            'order' => Sales\Order::class,
            'sales_document' => Sales\SalesDocument::class,
            'promotion' => Promotion::class,
            'notification_rule' => Core\NotificationRule::class,
            'delivery' => Delivery::class,
            'agency_account' => AgencyAccount::class,
            'agency_day' => AgencyDay::class,
            'agency_commission' => AgencyCommission::class,
            'shift' => Shift::class,
            'purchase_plan' => Purchasing\PurchasePlan::class,
            'online_payment' => Payments\OnlinePayment::class,
            // Purchasing
            'supplier' => Purchasing\Supplier::class,
            'purchase' => Purchasing\Purchase::class,
            'supplier_payment' => Purchasing\SupplierPayment::class,
            'purchase_return' => Purchasing\PurchaseReturn::class,
            // Finance
            'expense' => Finance\Expense::class,
            'expense_category' => Finance\ExpenseCategory::class,
            'faq' => \App\Models\Shop\Faq::class,
            'app_release' => \App\Models\Core\AppRelease::class,
            'account' => Finance\Account::class,
            'journal_entry' => Finance\JournalEntry::class,
            'fiscal_year' => Finance\FiscalYear::class,
            'fixed_asset' => FixedAsset::class,
            'bank_reconciliation' => BankReconciliation::class,
            'department' => Department::class,
            'position' => Position::class,
            'employee' => Employee::class,
            'employee_document' => EmployeeDocument::class,
            'attendance' => Attendance::class,
            'leave_type' => LeaveType::class,
            'leave_request' => LeaveRequest::class,
            'holiday' => Holiday::class,
            'salary_component' => SalaryComponent::class,
            'salary_advance' => SalaryAdvance::class,
            'payroll_run' => PayrollRun::class,
            'ai_conversation' => AiConversation::class,
            'budget' => Finance\Budget::class,
        ];
    }
}
