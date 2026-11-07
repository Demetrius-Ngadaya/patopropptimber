import type { SQLiteDatabase } from 'expo-sqlite';
import { enqueue, newId, transaction } from './outbox';
import { getMeta, setMeta } from './meta';
import { money, round2 } from '../lib/format';
import { t } from '../i18n';
import { runtime } from '../lib/runtime';
import type { Customer, Order, OrderStatus, PaymentMethod, SaleLine, SalePayment } from '../lib/types';

/**
 * Everything a user can change in the app. Each command updates the phone's
 * copy at once (the screen shows the result immediately, online or not) and
 * queues the same change for the server, in one transaction.
 */

/** The id the server knows, or a reference to the offline change that will create it. */
async function customerRef(tx: SQLiteDatabase, customer: Pick<Customer, 'id' | 'key'>): Promise<number | string> {
  if (customer.id) return customer.id;
  const row = await tx.getFirstAsync<{ id: string; result: string | null; status: string }>(
    "SELECT id, result, status FROM outbox WHERE type = 'customer.create' AND entity_key = ? ORDER BY seq DESC LIMIT 1",
    customer.key,
  );
  if (!row) throw new Error(t('This customer was not saved. Add them again.'));
  if (row.status === 'done' && row.result) {
    const id = JSON.parse(row.result).result_id;
    if (id) return Number(id);
  }
  return `@ref:${row.id}`;
}

export type NewSale = {
  lines: SaleLine[];
  customer: Customer | null;
  payments: SalePayment[];
  discount: number;
  /** Tax added on top when the business's prices exclude tax (0 when included). */
  tax: number;
  dueDate: string | null;
  notes: string | null;
};

async function nextLocalNumber(): Promise<string> {
  const d = new Date();
  const day = `${String(d.getFullYear()).slice(2)}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
  const counter = await getMeta<{ day: string; n: number }>('saleCounter', { day, n: 0 });
  const n = counter.day === day ? counter.n + 1 : 1;
  await setMeta('saleCounter', { day, n });
  return `APP-${day}-${String(n).padStart(3, '0')}`;
}

export function saleTotals(lines: SaleLine[], discount: number, payments: SalePayment[], tax = 0) {
  const subtotal = round2(lines.reduce((a, l) => a + l.line_total, 0));
  const total = round2(Math.max(0, subtotal - discount + tax));
  const tendered = round2(payments.reduce((a, p) => a + p.amount, 0));
  const paid = Math.min(total, tendered);
  // Change only comes out of cash.
  const cash = payments.filter((p) => p.method === 'cash').reduce((a, p) => a + p.amount, 0);
  const change = round2(Math.min(Math.max(0, tendered - total), cash));
  return { subtotal, total, tendered, paid: round2(paid), balance: round2(total - paid), change };
}

export async function recordSale(input: NewSale): Promise<string> {
  const localNumber = await nextLocalNumber();
  const key = `l:${newId()}`;
  const mutationId = newId();
  const totals = saleTotals(input.lines, input.discount, input.payments, input.tax);

  await transaction(async (tx) => {
    const payload: Record<string, unknown> = {
      items: input.lines.map((l) => ({ product_id: l.product_id, product_unit_id: l.product_unit_id, quantity: l.quantity })),
      payments: input.payments.filter((p) => p.amount > 0).map((p) => ({ method: p.method, amount: p.amount, reference: p.reference || null, provider: p.provider || null })),
      discount: input.discount || null,
      due_date: totals.balance > 0.004 ? input.dueDate : null,
      notes: input.notes || null,
    };
    if (input.customer) payload.customer_id = await customerRef(tx, input.customer);

    await tx.runAsync(
      `INSERT INTO sales (key, local_number, status, payment_status, customer_name, customer_key, total, amount_paid, balance, change_given,
         items_count, items_json, payments_json, sold_at, sync_state, mutation_id, cashier_id)
       VALUES (?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)`,
      key,
      localNumber,
      totals.balance > 0.004 ? (totals.paid > 0 ? 'partial' : 'unpaid') : 'paid',
      input.customer?.name ?? null,
      input.customer?.key ?? null,
      totals.total,
      totals.paid,
      totals.balance,
      totals.change,
      input.lines.length,
      JSON.stringify(input.lines),
      JSON.stringify(input.payments),
      new Date().toISOString(),
      mutationId,
      runtime.get().userId,
    );
    for (const line of input.lines) {
      await tx.runAsync('UPDATE products SET times_sold = times_sold + 1 WHERE id = ?', line.product_id);
    }
    if (input.customer && totals.balance > 0) {
      await tx.runAsync('UPDATE customers SET balance = balance + ? WHERE key = ?', totals.balance, input.customer.key);
    }
    await enqueue(tx, {
      id: mutationId,
      type: 'sale.create',
      payload,
      entityKey: key,
      summary: `${localNumber} · ${money(totals.total)}${input.customer ? ` · ${input.customer.name}` : ''}`,
    });
  }, ['sales', 'products', 'customers']);

  return key;
}

export type CustomerInput = {
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  customer_type: 'retail' | 'wholesale' | 'institution';
  allow_credit: boolean;
  credit_limit: number;
};

export async function createCustomer(input: CustomerInput): Promise<string> {
  const key = `l:${newId()}`;
  await transaction(async (tx) => {
    await tx.runAsync(
      `INSERT INTO customers (key, name, name_lc, phone, email, address, city, customer_type, allow_credit, credit_limit, balance, is_active, pending)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, 1)`,
      key, input.name, input.name.toLowerCase(), input.phone, input.email, input.address, input.city, input.customer_type, input.allow_credit ? 1 : 0, input.credit_limit,
    );
    await enqueue(tx, { type: 'customer.create', payload: { ...input }, entityKey: key, summary: input.name });
  }, ['customers']);
  return key;
}

export async function updateCustomer(customer: Customer, input: CustomerInput): Promise<void> {
  await transaction(async (tx) => {
    const target = await customerRef(tx, customer);
    await tx.runAsync(
      `UPDATE customers SET name = ?, name_lc = ?, phone = ?, email = ?, address = ?, city = ?, customer_type = ?, allow_credit = ?, credit_limit = ?, pending = 1 WHERE key = ?`,
      input.name, input.name.toLowerCase(), input.phone, input.email, input.address, input.city, input.customer_type, input.allow_credit ? 1 : 0, input.credit_limit, customer.key,
    );
    await enqueue(tx, {
      type: 'customer.update',
      targetId: target,
      payload: { ...input },
      baseUpdatedAt: customer.updated_at,
      entityKey: customer.key,
      summary: input.name,
    });
  }, ['customers']);
}

export async function receivePayment(customer: Customer, amount: number, method: PaymentMethod, reference: string | null): Promise<void> {
  await transaction(async (tx) => {
    const target = await customerRef(tx, customer);
    await tx.runAsync('UPDATE customers SET balance = MAX(0, balance - ?) WHERE key = ?', amount, customer.key);
    await enqueue(tx, {
      type: 'customer.payment',
      targetId: target,
      payload: { amount, method, reference: reference || null },
      entityKey: customer.key,
      summary: `${customer.name} · ${money(amount)}`,
    });
  }, ['customers']);
}

export async function setOrderStatus(order: Pick<Order, 'id' | 'number'>, status: OrderStatus, note: string | null = null): Promise<void> {
  await transaction(async (tx) => {
    await tx.runAsync('UPDATE orders SET pending_status = ? WHERE id = ?', status, order.id);
    await enqueue(tx, {
      type: 'order.status',
      targetId: order.id,
      payload: { status, note },
      entityKey: String(order.id),
      summary: `${order.number} → ${status}`,
    });
  }, ['orders']);
}

export async function completeOrder(order: Order, payments: SalePayment[]): Promise<void> {
  await transaction(async (tx) => {
    await tx.runAsync("UPDATE orders SET pending_status = 'completed' WHERE id = ?", order.id);
    await enqueue(tx, {
      type: 'order.complete',
      targetId: order.id,
      payload: { payments: payments.filter((p) => p.amount > 0).map((p) => ({ method: p.method, amount: p.amount, reference: p.reference || null })) },
      entityKey: String(order.id),
      summary: `${order.number} → completed`,
    });
  }, ['orders']);
}

export async function markNotificationRead(id: number): Promise<void> {
  if (!id) return;
  await transaction(async (tx) => {
    const res = await tx.runAsync('UPDATE notifications SET is_read = 1 WHERE id = ? AND is_read = 0', id);
    if (res.changes > 0) await enqueue(tx, { type: 'notification.read', targetId: id, payload: {} });
  }, ['notifications']);
}

export async function markAllNotificationsRead(): Promise<void> {
  await transaction(async (tx) => {
    const res = await tx.runAsync('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
    if (res.changes > 0) await enqueue(tx, { type: 'notification.read_all', payload: {} });
  }, ['notifications']);
}
