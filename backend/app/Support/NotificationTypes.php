<?php

namespace App\Support;

/**
 * Every kind of message the system sends on its own.
 *
 * Staff notifications always appear in the bell (to users who hold the
 * permission); admins choose per type which outside channels also carry them
 * to the recipient lists in Settings. Customer messages go to the customer's
 * own phone / email, on the channels chosen here or at the moment of sending.
 */
final class NotificationTypes
{
    /** @return array<string, array{label: string, permission: ?string, channels: array<int, string>, audience: string}> */
    public static function all(): array
    {
        return [
            // Staff
            'sales_report' => ['label' => 'Sales reports (daily, weekly, monthly, yearly)', 'permission' => 'reports.sales', 'channels' => ['sms', 'email'], 'audience' => 'staff'],
            'low_stock' => ['label' => 'A product reaches its reorder level', 'permission' => 'stock.alerts', 'channels' => ['email'], 'audience' => 'staff'],
            'out_of_stock' => ['label' => 'A product runs out of stock', 'permission' => 'stock.alerts', 'channels' => ['sms', 'email'], 'audience' => 'staff'],
            'stock_digest' => ['label' => 'Morning stock summary (low, out of stock, expiring)', 'permission' => 'stock.alerts', 'channels' => ['email'], 'audience' => 'staff'],
            'debt_overdue' => ['label' => 'Morning list of overdue customer debts', 'permission' => 'credit.view', 'channels' => ['email'], 'audience' => 'staff'],
            'new_order' => ['label' => 'New online order', 'permission' => 'orders.view', 'channels' => ['sms'], 'audience' => 'staff'],
            'sale_cancelled' => ['label' => 'A sale is cancelled', 'permission' => 'sales.view_all_users', 'channels' => [], 'audience' => 'staff'],
            'leave_request' => ['label' => 'An employee asks for leave', 'permission' => 'leave.approve', 'channels' => [], 'audience' => 'staff'],
            'online_payment' => ['label' => 'Order paid online (and refunds needed for cancelled paid orders)', 'permission' => 'orders.view', 'channels' => [], 'audience' => 'staff'],
            'agency_difference' => ['label' => 'Uwakala day closes short or over', 'permission' => 'agency.manage', 'channels' => ['sms'], 'audience' => 'staff'],
            'purchase_due' => ['label' => 'Supplier payment due (credit purchases)', 'permission' => 'suppliers.pay', 'channels' => ['sms', 'email'], 'audience' => 'staff'],
            'purchase_plan' => ['label' => 'Purchase plan waiting for approval or goods', 'permission' => 'purchase_plans.view', 'channels' => [], 'audience' => 'staff'],
            'shift' => ['label' => 'Shift cover requests and handovers (to the person concerned)', 'permission' => null, 'channels' => [], 'audience' => 'staff'],
            'note_reminder' => ['label' => 'Note reminders and notes shared with you', 'permission' => null, 'channels' => [], 'audience' => 'staff'],
            'payroll_prepared' => ['label' => 'Payroll is ready for approval', 'permission' => 'payroll.approve', 'channels' => [], 'audience' => 'staff'],
            // Customers
            'customer_receipt' => ['label' => 'Receipt to the customer after a sale', 'permission' => null, 'channels' => ['sms'], 'audience' => 'customer'],
            'customer_order_status' => ['label' => 'Order status updates to the customer', 'permission' => null, 'channels' => ['sms'], 'audience' => 'customer'],
            'customer_debt_reminder' => ['label' => 'Debt reminders to the customer', 'permission' => null, 'channels' => ['sms'], 'audience' => 'customer'],
            'customer_delivery' => ['label' => 'Delivery updates and tracking link to the customer', 'permission' => null, 'channels' => ['sms'], 'audience' => 'customer'],
        ];
    }

    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /** @return array<string, array<int, string>> */
    public static function defaultRouting(): array
    {
        return array_map(fn ($t) => $t['channels'], self::all());
    }
}
