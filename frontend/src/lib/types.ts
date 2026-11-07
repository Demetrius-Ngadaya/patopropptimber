export type Branch = { id: number; name: string; code: string; location?: string | null };

export type SessionUser = {
  id: number;
  type: "staff" | "customer";
  name: string;
  username: string;
  email: string | null;
  phone: string | null;
  must_change_password: boolean;
  last_login_at?: string | null;
  session_timeout_minutes: number;
  locale?: "en" | "sw";
  theme?: "light" | "dark" | "system";
  two_factor_enabled?: boolean;
  two_factor_required?: boolean;
  // staff
  role?: { id: number; name: string; slug: string; is_super_admin: boolean; is_developer?: boolean } | null;
  branch?: Branch | null;
  all_branches?: boolean;
  branches?: Branch[];
  permissions?: string[];
  disabled_modules?: string[];
  // customer
  customer?: { id: number; code: string; name: string; phone: string | null; email: string | null; address: string | null; city: string | null; balance: string } | null;
};

export type Product = {
  id: number;
  name: string;
  slug?: string;
  sku: string | null;
  barcode: string | null;
  category_id: number | null;
  unit_id: number | null;
  category?: { id: number; name: string } | null;
  unit?: { id: number; name?: string; short_name: string } | null;
  brand?: string | null;
  description?: string | null;
  cost_price?: string;
  selling_price: string;
  wholesale_price?: string | null;
  min_selling_price?: string | null;
  tax_rate?: string;
  reorder_level?: string;
  track_expiry: boolean;
  allow_decimal: boolean;
  is_service?: boolean;
  show_online?: boolean;
  is_active?: boolean;
  image_url?: string | null;
  stock_quantity?: string | number | null;
  wholesale_min_qty?: string | null;
  description_sw?: string | null;
  units?: ProductUnit[];
  /** Set by barcode lookup when a pack barcode (e.g. a carton) was scanned. */
  matched_unit_id?: number | null;
  deleted_at?: string | null;
};

/** A pack / alternative selling unit: 1 of it = factor base units. */
export type ProductUnit = {
  id: number;
  product_id: number;
  name: string;
  factor: string;
  barcode: string | null;
  retail_price: string;
  wholesale_price: string | null;
  is_active?: boolean;
  sort_order?: number;
  deleted_at?: string | null;
};

export type Customer = {
  id: number;
  code: string;
  name: string;
  phone: string | null;
  email?: string | null;
  balance: string;
  credit_limit: string;
  allow_credit: boolean;
  customer_type?: string;
  address?: string | null;
  is_active?: boolean;
  user?: { id: number; username: string; is_active: boolean; last_login_at: string | null } | null;
  deleted_at?: string | null;
  [key: string]: unknown;
};

export type SaleItem = {
  id: number;
  product_id: number;
  product_name: string;
  quantity: string;
  returned_quantity: string;
  unit_price: string;
  list_price: string;
  discount: string;
  line_total: string;
  unit_cost?: string;
  product?: { id: number; name: string; sku?: string; unit?: { short_name: string } | null };
};

export type Payment = {
  id: number;
  number: string;
  amount: string;
  method: string;
  provider: string | null;
  reference: string | null;
  type: string;
  paid_at: string;
};

export type Sale = {
  id: number;
  number: string;
  branch_id: number;
  customer_id: number | null;
  customer_name: string | null;
  customer_phone: string | null;
  channel: string;
  status: string;
  subtotal: string;
  item_discount: string;
  discount: string;
  tax: string;
  delivery_fee: string;
  total: string;
  cost_total?: string;
  amount_paid: string;
  balance: string;
  returned_amount: string;
  change_given: string;
  payment_status: string;
  is_credit?: boolean;
  credit_amount?: string;
  price_level?: string;
  due_date: string | null;
  sold_at: string;
  notes: string | null;
  cancel_reason?: string | null;
  items?: SaleItem[];
  payments?: Payment[];
  customer?: Customer | null;
  cashier?: { id: number; name: string } | null;
  branch?: Branch & { address?: string | null; phone?: string | null };
  returns?: Array<{ id: number; number: string; total: string; refund_amount: string; reason: string; created_at: string }>;
};

export type CartLine = {
  /** product + pack: the same product can be sold loose and by the carton. */
  key: string;
  product_id: number;
  product_unit_id?: number | null;
  units?: ProductUnit[];
  base_unit?: string | null;
  factor?: number;
  price_level?: string;
  name: string;
  unit?: string | null;
  allow_decimal: boolean;
  list_price: number;
  unit_price: number;
  quantity: number;
  discount: number;
  available?: number | null;
  is_service?: boolean;
  /** The cashier typed this price (sent to the server; needs pos.change_price). */
  manual?: boolean;
};
