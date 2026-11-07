import { getDb } from './database';
import type { AppNotification, Category, Customer, Order, OrderStatus, Product, ProductUnit, Sale } from '../lib/types';

/**
 * Read side of the local database. Every screen reads from here (never from
 * the network directly), so the app behaves the same online and offline.
 */

const bool = (v: unknown) => v === 1 || v === true || v === '1';
const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const numOrNull = (v: unknown) => (v === null || v === undefined ? null : Number(v));
const json = <T>(v: unknown, fallback: T): T => {
  try {
    return typeof v === 'string' ? (JSON.parse(v) as T) : fallback;
  } catch {
    return fallback;
  }
};

// Stock shown = server stock − quantities in sales not yet confirmed by the server.
const PRODUCT_SELECT = `
  WITH pending AS (
    SELECT CAST(json_extract(j.value, '$.product_id') AS INTEGER) AS pid, SUM(json_extract(j.value, '$.base_quantity')) AS q
    FROM sales s, json_each(s.items_json) j WHERE s.sync_state != 'synced' GROUP BY pid
  )
  SELECT p.*, COALESCE(st.quantity, 0) - COALESCE(pending.q, 0) AS stock
  FROM products p
  LEFT JOIN stock st ON st.product_id = p.id
  LEFT JOIN pending ON pending.pid = p.id
`;

function toProduct(r: Record<string, unknown>): Product {
  return {
    id: num(r.id),
    name: String(r.name),
    sku: (r.sku as string) ?? null,
    barcode: (r.barcode as string) ?? null,
    category_id: numOrNull(r.category_id),
    unit: (r.unit as string) ?? null,
    selling_price: num(r.selling_price),
    wholesale_price: numOrNull(r.wholesale_price),
    wholesale_min_qty: numOrNull(r.wholesale_min_qty),
    min_selling_price: numOrNull(r.min_selling_price),
    tax_rate: num(r.tax_rate),
    allow_decimal: bool(r.allow_decimal),
    is_service: bool(r.is_service),
    reorder_level: numOrNull(r.reorder_level),
    is_active: bool(r.is_active),
    image_url: (r.image_url as string) ?? null,
    stock: num(r.stock),
    times_sold: num(r.times_sold),
  };
}

export type ProductFilter = { search?: string; categoryId?: number | null; inStockOnly?: boolean; lowStockOnly?: boolean; limit?: number; offset?: number };

export async function listProducts(f: ProductFilter = {}): Promise<Product[]> {
  const db = await getDb();
  const where = ['p.is_active = 1'];
  const params: (string | number)[] = [];
  const term = f.search?.trim().toLowerCase();
  if (term) {
    // Every word must match the name, or the whole text the code.
    const words = term.split(/\s+/).filter(Boolean).slice(0, 5);
    where.push(`((${words.map(() => 'p.name_lc LIKE ?').join(' AND ')}) OR p.sku = ? OR p.barcode = ?)`);
    params.push(...words.map((w) => `%${w}%`), f.search!.trim(), f.search!.trim());
  }
  if (f.categoryId) {
    where.push('p.category_id = ?');
    params.push(f.categoryId);
  }
  // Without a search: only what this branch carries (has stock records for), not
  // the whole catalogue of every branch (a butchery's meat in a supermarket).
  if (!term) where.push('(p.is_service = 1 OR st.product_id IS NOT NULL)');
  if (f.inStockOnly) where.push('(p.is_service = 1 OR COALESCE(st.quantity, 0) - COALESCE(pending.q, 0) > 0)');
  if (f.lowStockOnly) where.push('p.is_service = 0 AND COALESCE(st.quantity, 0) - COALESCE(pending.q, 0) <= COALESCE(p.reorder_level, 0)');
  // Most sold first when not searching: the till's quick grid.
  const order = term ? 'CASE WHEN p.name_lc LIKE ? THEN 0 ELSE 1 END, p.times_sold DESC, p.name_lc' : 'p.times_sold DESC, p.name_lc';
  if (term) params.push(`${term}%`);
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `${PRODUCT_SELECT} WHERE ${where.join(' AND ')} ORDER BY ${order} LIMIT ? OFFSET ?`,
    ...params,
    f.limit ?? 60,
    f.offset ?? 0,
  );
  return rows.map(toProduct);
}

export async function getProduct(id: number): Promise<Product | null> {
  const db = await getDb();
  const row = await db.getFirstAsync<Record<string, unknown>>(`${PRODUCT_SELECT} WHERE p.id = ?`, id);
  return row ? toProduct(row) : null;
}

/** A scanned code: the product's own barcode / SKU, or a pack unit's barcode. */
export async function findByCode(code: string): Promise<{ product: Product; unit: ProductUnit | null } | null> {
  const db = await getDb();
  const c = code.trim();
  const row = await db.getFirstAsync<Record<string, unknown>>(`${PRODUCT_SELECT} WHERE p.is_active = 1 AND (p.barcode = ? OR p.sku = ?) LIMIT 1`, c, c);
  if (row) return { product: toProduct(row), unit: null };
  const unit = await db.getFirstAsync<Record<string, unknown>>('SELECT * FROM product_units WHERE barcode = ? AND is_active = 1 LIMIT 1', c);
  if (!unit) return null;
  const product = await getProduct(num(unit.product_id));
  return product ? { product, unit: toUnit(unit) } : null;
}

function toUnit(r: Record<string, unknown>): ProductUnit {
  return {
    id: num(r.id),
    product_id: num(r.product_id),
    name: String(r.name),
    factor: num(r.factor) || 1,
    barcode: (r.barcode as string) ?? null,
    retail_price: num(r.retail_price),
    wholesale_price: numOrNull(r.wholesale_price),
    is_active: bool(r.is_active),
  };
}

export async function unitsFor(productId: number): Promise<ProductUnit[]> {
  const db = await getDb();
  const rows = await db.getAllAsync<Record<string, unknown>>(
    'SELECT * FROM product_units WHERE product_id = ? AND is_active = 1 ORDER BY sort_order, factor',
    productId,
  );
  return rows.map(toUnit);
}

export async function listCategories(): Promise<Category[]> {
  const db = await getDb();
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `SELECT c.* FROM categories c WHERE c.is_active = 1 AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_active = 1)
     ORDER BY c.sort_order, c.name`,
  );
  return rows.map((r) => ({ id: num(r.id), parent_id: numOrNull(r.parent_id), name: String(r.name), sort_order: num(r.sort_order), is_active: bool(r.is_active) }));
}

export async function stockSummary(): Promise<{ products: number; low: number; out: number }> {
  const db = await getDb();
  const row = await db.getFirstAsync<{ products: number; low: number; out: number }>(
    `WITH x AS (${PRODUCT_SELECT} WHERE p.is_active = 1 AND p.is_service = 0 AND st.product_id IS NOT NULL)
     SELECT COUNT(*) AS products,
            SUM(CASE WHEN stock > 0 AND stock <= COALESCE(reorder_level, 0) THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out FROM x`,
  );
  return { products: num(row?.products), low: num(row?.low), out: num(row?.out) };
}

// ------------------------------------------------------------------ customers

function toCustomer(r: Record<string, unknown>): Customer {
  return {
    key: String(r.key),
    id: numOrNull(r.id),
    code: (r.code as string) ?? null,
    name: String(r.name),
    phone: (r.phone as string) ?? null,
    email: (r.email as string) ?? null,
    address: (r.address as string) ?? null,
    city: (r.city as string) ?? null,
    customer_type: (r.customer_type as Customer['customer_type']) ?? null,
    allow_credit: bool(r.allow_credit),
    credit_limit: num(r.credit_limit),
    balance: num(r.balance),
    is_active: bool(r.is_active),
    updated_at: (r.updated_at as string) ?? null,
    pending: bool(r.pending),
  };
}

export async function listCustomers(search = '', limit = 60, offset = 0, owingOnly = false): Promise<Customer[]> {
  const db = await getDb();
  const term = search.trim().toLowerCase();
  const where = ['is_active = 1'];
  const params: (string | number)[] = [];
  if (term) {
    where.push('(name_lc LIKE ? OR phone LIKE ? OR code LIKE ?)');
    params.push(`%${term}%`, `%${term.replace(/\s/g, '')}%`, `%${term}%`);
  }
  if (owingOnly) where.push('balance > 0.004');
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `SELECT * FROM customers WHERE ${where.join(' AND ')} ORDER BY ${owingOnly ? 'balance DESC' : 'pending DESC, name_lc'} LIMIT ? OFFSET ?`,
    ...params,
    limit,
    offset,
  );
  return rows.map(toCustomer);
}

export async function getCustomer(key: string): Promise<Customer | null> {
  const db = await getDb();
  const row = await db.getFirstAsync<Record<string, unknown>>('SELECT * FROM customers WHERE key = ? OR id = ?', key, Number(key.replace(/^s:/, '')) || -1);
  return row ? toCustomer(row) : null;
}

// --------------------------------------------------------------------- orders

function toOrder(r: Record<string, unknown>): Order {
  return {
    id: num(r.id),
    number: String(r.number),
    status: r.status as OrderStatus,
    source: (r.source as string) ?? null,
    customer_name: (r.customer_name as string) ?? null,
    customer_phone: (r.customer_phone as string) ?? null,
    contact_phone: (r.contact_phone as string) ?? null,
    delivery_method: (r.delivery_method as string) ?? null,
    delivery_address: (r.delivery_address as string) ?? null,
    payment_method: (r.payment_method as string) ?? null,
    subtotal: num(r.subtotal),
    delivery_fee: num(r.delivery_fee),
    discount: num(r.discount),
    total: num(r.total),
    paid_online: num(r.paid_online),
    customer_notes: (r.customer_notes as string) ?? null,
    items: json(r.items_json, []),
    created_at: String(r.created_at ?? ''),
    updated_at: String(r.updated_at ?? ''),
    pending_status: (r.pending_status as OrderStatus) ?? null,
  };
}

export const OPEN_STATUSES: OrderStatus[] = ['pending', 'confirmed', 'processing', 'ready', 'out_for_delivery'];

export async function listOrders(filter: 'open' | 'completed' | 'cancelled' | 'all', search = ''): Promise<Order[]> {
  const db = await getDb();
  const where: string[] = [];
  const params: string[] = [];
  const status = 'COALESCE(pending_status, status)';
  if (filter === 'open') {
    where.push(`${status} IN (${OPEN_STATUSES.map(() => '?').join(',')})`);
    params.push(...OPEN_STATUSES);
  }
  if (filter === 'completed') where.push(`${status} = 'completed'`);
  if (filter === 'cancelled') where.push(`${status} = 'cancelled'`);
  if (search.trim()) {
    where.push('(number LIKE ? OR customer_name LIKE ? OR contact_phone LIKE ?)');
    const s = `%${search.trim()}%`;
    params.push(s, s, s);
  }
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `SELECT * FROM orders ${where.length ? `WHERE ${where.join(' AND ')}` : ''} ORDER BY created_at DESC LIMIT 200`,
    ...params,
  );
  return rows.map(toOrder);
}

export async function getOrder(id: number): Promise<Order | null> {
  const db = await getDb();
  const row = await db.getFirstAsync<Record<string, unknown>>('SELECT * FROM orders WHERE id = ?', id);
  return row ? toOrder(row) : null;
}

export async function countOpenOrders(): Promise<number> {
  const db = await getDb();
  const row = await db.getFirstAsync<{ n: number }>(
    `SELECT COUNT(*) AS n FROM orders WHERE COALESCE(pending_status, status) IN (${OPEN_STATUSES.map(() => '?').join(',')})`,
    ...OPEN_STATUSES,
  );
  return num(row?.n);
}

// ---------------------------------------------------------------------- sales

function toSale(r: Record<string, unknown>): Sale {
  return {
    key: String(r.key),
    id: numOrNull(r.id),
    number: (r.number as string) ?? null,
    local_number: (r.local_number as string) ?? null,
    status: String(r.status ?? 'completed'),
    payment_status: (r.payment_status as string) ?? null,
    customer_name: (r.customer_name as string) ?? null,
    customer_key: (r.customer_key as string) ?? null,
    total: num(r.total),
    amount_paid: num(r.amount_paid),
    balance: num(r.balance),
    change_given: num(r.change_given),
    items_count: num(r.items_count),
    items: json(r.items_json, []),
    payments: json(r.payments_json, []),
    sold_at: String(r.sold_at),
    sync_state: (r.sync_state as Sale['sync_state']) ?? 'synced',
    mutation_id: (r.mutation_id as string) ?? null,
    cashier_id: numOrNull(r.cashier_id),
    message: (r.message as string) ?? null,
  };
}

export async function listSales(limit = 100, onlyUnsynced = false): Promise<Sale[]> {
  const db = await getDb();
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `SELECT * FROM sales ${onlyUnsynced ? "WHERE sync_state != 'synced'" : ''} ORDER BY sold_at DESC LIMIT ?`,
    limit,
  );
  return rows.map(toSale);
}

export async function getSale(key: string): Promise<Sale | null> {
  const db = await getDb();
  const row = await db.getFirstAsync<Record<string, unknown>>('SELECT * FROM sales WHERE key = ?', key);
  return row ? toSale(row) : null;
}

export type DaySummary = { total: number; count: number; cash: number; credit: number; unsynced: number; hourly: number[] };

/** Today's sales on this phone's copy (server sales of the branch + sales made here). */
export async function todaySummary(cashierId?: number | null): Promise<DaySummary> {
  const db = await getDb();
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `SELECT total, balance, sold_at, sync_state, payments_json FROM sales
     WHERE sold_at >= ? AND status != 'cancelled' ${cashierId ? 'AND (cashier_id = ? OR cashier_id IS NULL)' : ''}`,
    start.toISOString(),
    ...(cashierId ? [cashierId] : []),
  );
  const hourly = Array.from({ length: 24 }, () => 0);
  const summary: DaySummary = { total: 0, count: 0, cash: 0, credit: 0, unsynced: 0, hourly };
  for (const r of rows) {
    const total = num(r.total);
    summary.total += total;
    summary.count++;
    summary.credit += num(r.balance);
    summary.cash += json<{ method: string; amount: number }[]>(r.payments_json, [])
      .filter((p) => p.method === 'cash')
      .reduce((a, p) => a + Number(p.amount), 0);
    if (r.sync_state !== 'synced') summary.unsynced++;
    hourly[new Date(String(r.sold_at)).getHours()] += total;
  }
  return summary;
}

// -------------------------------------------------------------- notifications

export async function listNotifications(unreadOnly = false, limit = 100): Promise<AppNotification[]> {
  const db = await getDb();
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `SELECT * FROM notifications ${unreadOnly ? 'WHERE is_read = 0' : ''} ORDER BY created_at DESC, id DESC LIMIT ?`,
    limit,
  );
  return rows.map((r) => ({
    id: num(r.id),
    type: String(r.type ?? ''),
    category: (r.category as AppNotification['category']) ?? 'general',
    level: String(r.level ?? 'info'),
    title: String(r.title),
    body: (r.body as string) ?? null,
    url: (r.url as string) ?? null,
    is_read: bool(r.is_read),
    created_at: String(r.created_at ?? ''),
  }));
}

export async function unreadCount(): Promise<number> {
  const db = await getDb();
  const row = await db.getFirstAsync<{ n: number }>('SELECT COUNT(*) AS n FROM notifications WHERE is_read = 0');
  return num(row?.n);
}
