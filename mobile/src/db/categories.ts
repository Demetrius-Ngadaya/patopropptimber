import type { NotificationCategory } from '../lib/types';

/** Same grouping as the server (backend/app/Support/NotificationCategories.php). */
const TYPES: Record<Exclude<NotificationCategory, 'general'>, string[]> = {
  orders: ['new_order', 'online_payment'],
  stock: ['low_stock', 'out_of_stock', 'stock_digest'],
  money: ['sales_report', 'debt_overdue', 'purchase_due', 'agency_difference', 'sale_cancelled'],
  team: ['leave_request', 'shift', 'payroll_prepared', 'purchase_plan', 'note_reminder'],
};

export const NOTIFICATION_CATEGORIES: NotificationCategory[] = ['orders', 'stock', 'money', 'team', 'general'];

export function categoryForType(type: string | null | undefined): NotificationCategory {
  for (const [category, types] of Object.entries(TYPES)) {
    if (type && types.includes(type)) return category as NotificationCategory;
  }
  return 'general';
}
