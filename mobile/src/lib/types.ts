/** Shapes returned by the PatoPro API (backend/app/Support/UserPayload.php and the mobile sync). */

export type Branch = { id: number; name: string; code: string };

export type User = {
  id: number;
  type: 'staff' | 'customer';
  name: string;
  username: string | null;
  email: string | null;
  phone: string | null;
  locale: 'en' | 'sw';
  role: { id: number; name: string; slug: string; is_super_admin: boolean; is_developer: boolean } | null;
  branch: Branch | null;
  all_branches: boolean;
  branches: Branch[];
  permissions: string[];
  disabled_modules?: string[];
};

export type BusinessSettings = {
  business_name?: string;
  currency?: string;
  business_phone?: string;
  business_address?: string;
  business_logo_url?: string | null;
  pos_show_categories?: boolean;
  allow_negative_stock?: boolean;
  auto_wholesale_by_quantity?: boolean;
  receipt_footer?: string;
  [key: string]: unknown;
};

export type NotificationCategory = 'orders' | 'stock' | 'money' | 'team' | 'general';

export type AlertMode = { enabled: boolean; voice: boolean; sound: boolean; vibrate: boolean };

export type Product = {
  id: number;
  name: string;
  sku: string | null;
  barcode: string | null;
  category_id: number | null;
  unit: string | null;
  selling_price: number;
  wholesale_price: number | null;
  wholesale_min_qty: number | null;
  min_selling_price: number | null;
  tax_rate: number;
  allow_decimal: boolean;
  is_service: boolean;
  reorder_level: number | null;
  is_active: boolean;
  image_url: string | null;
  /** Stock on the server for the selected branch, minus sales not yet synced. */
  stock: number;
  times_sold: number;
};

export type ProductUnit = {
  id: number;
  product_id: number;
  name: string;
  factor: number;
  barcode: string | null;
  retail_price: number;
  wholesale_price: number | null;
  is_active: boolean;
};

export type Category = { id: number; parent_id: number | null; name: string; sort_order: number; is_active: boolean };

export type Customer = {
  key: string;
  id: number | null;
  code: string | null;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  customer_type: 'retail' | 'wholesale' | 'institution' | null;
  allow_credit: boolean;
  credit_limit: number;
  balance: number;
  is_active: boolean;
  updated_at: string | null;
  pending: boolean;
};

export type OrderStatus = 'pending' | 'confirmed' | 'processing' | 'ready' | 'out_for_delivery' | 'completed' | 'cancelled';

export type OrderItem = { product_id: number | null; name: string; quantity: number; unit_price: number; line_total: number };

export type Order = {
  id: number;
  number: string;
  status: OrderStatus;
  source: string | null;
  customer_name: string | null;
  customer_phone: string | null;
  contact_phone: string | null;
  delivery_method: string | null;
  delivery_address: string | null;
  payment_method: string | null;
  subtotal: number;
  delivery_fee: number;
  discount: number;
  total: number;
  paid_online: number;
  customer_notes: string | null;
  items: OrderItem[];
  created_at: string;
  updated_at: string;
  /** A status chosen on this phone, not yet confirmed by the server. */
  pending_status: OrderStatus | null;
};

export type SaleSyncState = 'synced' | 'pending' | 'failed' | 'attention';

export type SaleLine = {
  product_id: number;
  product_unit_id: number | null;
  name: string;
  unit: string | null;
  quantity: number;
  base_quantity: number;
  unit_price: number;
  line_total: number;
};

export type SalePayment = { method: PaymentMethod; amount: number; reference?: string | null; provider?: string | null };

export type PaymentMethod = 'cash' | 'mobile_money' | 'bank' | 'card' | 'cheque';

export type Sale = {
  key: string;
  id: number | null;
  number: string | null;
  local_number: string | null;
  status: string;
  payment_status: string | null;
  customer_name: string | null;
  customer_key: string | null;
  total: number;
  amount_paid: number;
  balance: number;
  change_given: number;
  items_count: number;
  items: SaleLine[];
  payments: SalePayment[];
  sold_at: string;
  sync_state: SaleSyncState;
  mutation_id: string | null;
  cashier_id: number | null;
  message: string | null;
};

export type AppNotification = {
  id: number;
  type: string;
  category: NotificationCategory;
  level: 'info' | 'success' | 'warning' | 'danger' | string;
  title: string;
  body: string | null;
  url: string | null;
  is_read: boolean;
  created_at: string;
};
