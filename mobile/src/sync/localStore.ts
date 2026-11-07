import type { SQLiteDatabase } from 'expo-sqlite';
import { getDb, notifyChanged, withWrite } from '../db/database';
import { categoryForType } from '../db/categories';
import { runtime } from '../lib/runtime';
import type { EntityPage, OutboxItem, SyncStore } from './engine';

/** The sync engine's view of the local SQLite database. */

type Row = Record<string, unknown>;
const b = (v: unknown) => (v ? 1 : 0);
const n = (v: unknown) => (v === null || v === undefined || v === '' ? null : Number(v));
const s = (v: unknown) => (v === null || v === undefined ? null : String(v));

/** Kinds of records that belong to one branch: cleared when the branch changes. */
export const BRANCH_ENTITIES = ['stock', 'orders', 'sales'] as const;

const UPSERT: Record<string, (tx: SQLiteDatabase, r: Row) => Promise<unknown>> = {
  categories: (tx, r) =>
    tx.runAsync(
      `INSERT INTO categories (id, parent_id, name, sort_order, is_active) VALUES (?, ?, ?, ?, ?)
       ON CONFLICT(id) DO UPDATE SET parent_id = excluded.parent_id, name = excluded.name, sort_order = excluded.sort_order, is_active = excluded.is_active`,
      n(r.id), n(r.parent_id), String(r.name), Number(r.sort_order ?? 0), b(r.is_active),
    ),
  products: (tx, r) =>
    tx.runAsync(
      `INSERT INTO products (id, name, name_lc, sku, barcode, category_id, unit, selling_price, wholesale_price, wholesale_min_qty,
         min_selling_price, tax_rate, allow_decimal, is_service, reorder_level, is_active, image_url, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON CONFLICT(id) DO UPDATE SET name = excluded.name, name_lc = excluded.name_lc, sku = excluded.sku, barcode = excluded.barcode,
         category_id = excluded.category_id, unit = excluded.unit, selling_price = excluded.selling_price, wholesale_price = excluded.wholesale_price,
         wholesale_min_qty = excluded.wholesale_min_qty, min_selling_price = excluded.min_selling_price, tax_rate = excluded.tax_rate,
         allow_decimal = excluded.allow_decimal, is_service = excluded.is_service, reorder_level = excluded.reorder_level,
         is_active = excluded.is_active, image_url = excluded.image_url, updated_at = excluded.updated_at`,
      n(r.id), String(r.name), String(r.name).toLowerCase(), s(r.sku), s(r.barcode), n(r.category_id), s(r.unit), Number(r.selling_price ?? 0),
      n(r.wholesale_price), n(r.wholesale_min_qty), n(r.min_selling_price), Number(r.tax_rate ?? 0), b(r.allow_decimal), b(r.is_service),
      n(r.reorder_level), b(r.is_active), s(r.image_url), s(r.updated_at),
    ),
  units: (tx, r) =>
    tx.runAsync(
      `INSERT INTO product_units (id, product_id, name, factor, barcode, retail_price, wholesale_price, is_active, sort_order)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON CONFLICT(id) DO UPDATE SET product_id = excluded.product_id, name = excluded.name, factor = excluded.factor, barcode = excluded.barcode,
         retail_price = excluded.retail_price, wholesale_price = excluded.wholesale_price, is_active = excluded.is_active, sort_order = excluded.sort_order`,
      n(r.id), n(r.product_id), String(r.name), Number(r.factor ?? 1), s(r.barcode), Number(r.retail_price ?? 0), n(r.wholesale_price), b(r.is_active), Number(r.sort_order ?? 0),
    ),
  stock: (tx, r) =>
    tx.runAsync(
      `INSERT INTO stock (product_id, quantity, server_id) VALUES (?, ?, ?)
       ON CONFLICT(product_id) DO UPDATE SET quantity = excluded.quantity, server_id = excluded.server_id`,
      n(r.product_id), Number(r.quantity ?? 0), n(r.id),
    ),
  customers: (tx, r) =>
    tx.runAsync(
      // A customer edited on this phone and not yet sent keeps the phone's version until the server answers.
      `INSERT INTO customers (key, id, code, name, name_lc, phone, email, address, city, customer_type, allow_credit, credit_limit, balance, is_active, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON CONFLICT(id) DO UPDATE SET code = excluded.code, balance = excluded.balance, updated_at = excluded.updated_at,
         name = CASE WHEN customers.pending = 1 THEN customers.name ELSE excluded.name END,
         name_lc = CASE WHEN customers.pending = 1 THEN customers.name_lc ELSE excluded.name_lc END,
         phone = CASE WHEN customers.pending = 1 THEN customers.phone ELSE excluded.phone END,
         email = CASE WHEN customers.pending = 1 THEN customers.email ELSE excluded.email END,
         address = CASE WHEN customers.pending = 1 THEN customers.address ELSE excluded.address END,
         city = CASE WHEN customers.pending = 1 THEN customers.city ELSE excluded.city END,
         customer_type = excluded.customer_type, allow_credit = excluded.allow_credit, credit_limit = excluded.credit_limit, is_active = excluded.is_active`,
      `s:${r.id}`, n(r.id), s(r.code), String(r.name), String(r.name).toLowerCase(), s(r.phone), s(r.email), s(r.address), s(r.city),
      s(r.customer_type), b(r.allow_credit), Number(r.credit_limit ?? 0), Number(r.balance ?? 0), b(r.is_active), s(r.updated_at),
    ),
  orders: (tx, r) => {
    const customer = (r.customer ?? {}) as Row;
    return tx.runAsync(
      `INSERT INTO orders (id, number, status, source, customer_name, customer_phone, contact_phone, delivery_method, delivery_address, payment_method,
         subtotal, delivery_fee, discount, total, paid_online, customer_notes, items_json, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON CONFLICT(id) DO UPDATE SET number = excluded.number, status = excluded.status, source = excluded.source,
         customer_name = excluded.customer_name, customer_phone = excluded.customer_phone, contact_phone = excluded.contact_phone,
         delivery_method = excluded.delivery_method, delivery_address = excluded.delivery_address, payment_method = excluded.payment_method,
         subtotal = excluded.subtotal, delivery_fee = excluded.delivery_fee, discount = excluded.discount, total = excluded.total,
         paid_online = excluded.paid_online, customer_notes = excluded.customer_notes, items_json = excluded.items_json,
         created_at = excluded.created_at, updated_at = excluded.updated_at,
         pending_status = CASE WHEN orders.pending_status = excluded.status THEN NULL ELSE orders.pending_status END`,
      n(r.id), String(r.number), String(r.status), s(r.source), s(customer.name), s(customer.phone), s(r.contact_phone), s(r.delivery_method),
      s(r.delivery_address), s(r.payment_method), Number(r.subtotal ?? 0), Number(r.delivery_fee ?? 0), Number(r.discount ?? 0), Number(r.total ?? 0),
      Number(r.paid_online ?? 0), s(r.customer_notes), JSON.stringify(r.items ?? []), s(r.created_at), s(r.updated_at),
    );
  },
  sales: (tx, r) =>
    tx.runAsync(
      `INSERT INTO sales (key, id, number, status, payment_status, customer_name, total, amount_paid, balance, items_count, sold_at, sync_state, cashier_id, payments_json)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
       ON CONFLICT(id) DO UPDATE SET number = excluded.number, status = excluded.status, payment_status = excluded.payment_status,
         payments_json = CASE WHEN excluded.payments_json = '[]' THEN sales.payments_json ELSE excluded.payments_json END,
         customer_name = COALESCE(excluded.customer_name, sales.customer_name), total = excluded.total, amount_paid = excluded.amount_paid,
         balance = excluded.balance, items_count = excluded.items_count, sync_state = 'synced', message = NULL`,
      `s:${r.id}`, n(r.id), s(r.number), String(r.status ?? 'completed'), s(r.payment_status), s(r.customer_name), Number(r.total ?? 0),
      Number(r.amount_paid ?? 0), Number(r.balance ?? 0), Number(r.items_count ?? 0), String(r.sold_at), n(r.cashier_id),
      Array.isArray(r.payments) ? JSON.stringify(r.payments) : '[]',
    ),
  notifications: (tx, r) =>
    tx.runAsync(
      // Read on this phone but not yet sent: stays read.
      `INSERT INTO notifications (id, type, category, level, title, body, url, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON CONFLICT(id) DO UPDATE SET title = excluded.title, body = excluded.body, url = excluded.url, is_read = MAX(notifications.is_read, excluded.is_read)`,
      n(r.id), s(r.type), categoryForType(s(r.type)), s(r.level), String(r.title), s(r.body), s(r.url), b(r.read), s(r.created_at),
    ),
};

const DELETE: Record<string, string> = {
  categories: 'DELETE FROM categories WHERE id = ?',
  products: 'DELETE FROM products WHERE id = ?',
  units: 'DELETE FROM product_units WHERE id = ?',
  customers: 'DELETE FROM customers WHERE id = ? AND pending = 0',
};

export type PulledHook = (entity: string, rows: Row[], firstPage: boolean) => void | Promise<void>;

export function createLocalStore(onPulled?: PulledHook): SyncStore {
  return {
    async dueMutations(now, force, limit) {
      const db = await getDb();
      const rows = await db.getAllAsync<Row>(
        `SELECT * FROM outbox WHERE user_id = ? AND (status = 'pending' OR (status = 'failed' AND (? = 1 OR next_attempt_at <= ?)))
         ORDER BY seq LIMIT ?`,
        runtime.get().userId ?? 0,
        force ? 1 : 0,
        now,
        limit,
      );
      return rows.map(
        (r): OutboxItem => ({
          id: String(r.id),
          seq: Number(r.seq),
          type: r.type as OutboxItem['type'],
          target_id: (r.target_id as string) ?? null,
          payload: JSON.parse(String(r.payload ?? '{}')),
          branch_id: n(r.branch_id),
          created_at: String(r.created_at),
          base_updated_at: (r.base_updated_at as string) ?? null,
          attempts: Number(r.attempts ?? 0),
        }),
      );
    },

    async markApplied(item, result) {
      const db = await getDb();
      const data = (result.data ?? {}) as Row;
      const id = result.result_id ?? n(data.id);
      await withWrite(db, async (tx) => {
        await tx.runAsync(
          "UPDATE outbox SET status = 'done', done_at = ?, result = ?, last_error = NULL, last_code = NULL, http_status = ? WHERE id = ?",
          new Date().toISOString(), JSON.stringify(result), result.http_status ?? 200, item.id,
        );
        switch (item.type) {
          case 'sale.create': {
            const local = await tx.getFirstAsync<{ key: string }>('SELECT key FROM sales WHERE mutation_id = ?', item.id);
            if (local && id) {
              // The pull may already have brought the same sale: keep one row.
              await tx.runAsync('DELETE FROM sales WHERE id = ? AND key != ?', id, local.key);
              await tx.runAsync(
                `UPDATE sales SET id = ?, number = COALESCE(?, number), total = COALESCE(?, total), amount_paid = COALESCE(?, amount_paid),
                   balance = COALESCE(?, balance), change_given = COALESCE(?, change_given), payment_status = COALESCE(?, payment_status),
                   sync_state = 'synced', message = NULL WHERE key = ?`,
                id, s(data.number), n(data.total), n(data.amount_paid), n(data.balance), n(data.change_given), s(data.payment_status), local.key,
              );
            }
            break;
          }
          case 'customer.create': {
            const key = (await tx.getFirstAsync<{ entity_key: string }>('SELECT entity_key FROM outbox WHERE id = ?', item.id))?.entity_key;
            if (key && id) {
              await tx.runAsync('DELETE FROM customers WHERE id = ? AND key != ?', id, key);
              await tx.runAsync('UPDATE customers SET id = ?, code = COALESCE(?, code), updated_at = COALESCE(?, updated_at), pending = 0 WHERE key = ?', id, s(data.code), s(data.updated_at), key);
              // Sales waiting for this customer now point at the real one.
              await tx.runAsync("UPDATE sales SET customer_key = ? WHERE customer_key = ?", `s:${id}`, key);
            }
            break;
          }
          case 'customer.update':
            await tx.runAsync('UPDATE customers SET pending = 0, updated_at = COALESCE(?, updated_at) WHERE id = ?', s(data.updated_at), id);
            break;
          case 'customer.payment':
            if (data.balance !== undefined) await tx.runAsync('UPDATE customers SET balance = ? WHERE id = ?', Number(data.balance), id);
            break;
          case 'order.status':
          case 'order.complete':
            await tx.runAsync('UPDATE orders SET status = COALESCE(?, status), pending_status = NULL WHERE id = ?', s(data.status), id);
            break;
        }
      });
      notifyChanged('outbox', 'sales', 'customers', 'orders');
    },

    async markAttention(item, result) {
      const db = await getDb();
      await withWrite(db, async (tx) => {
        await tx.runAsync(
          "UPDATE outbox SET status = 'attention', last_error = ?, last_code = ?, http_status = ?, result = ?, attempts = attempts + 1 WHERE id = ?",
          result.message ?? 'Not saved.', result.code ?? null, result.http_status ?? null, JSON.stringify(result), item.id,
        );
        if (item.type === 'sale.create') {
          await tx.runAsync("UPDATE sales SET sync_state = 'attention', message = ? WHERE mutation_id = ?", result.message ?? null, item.id);
        }
        // The server kept its own order status: show that one.
        if (item.type === 'order.status' || item.type === 'order.complete') {
          await tx.runAsync('UPDATE orders SET pending_status = NULL WHERE id = ?', Number(item.target_id));
        }
        // Someone else changed the customer: the next pull brings their version.
        if (item.type === 'customer.update' && result.status === 'conflict') {
          await tx.runAsync('UPDATE customers SET pending = 0 WHERE id = ?', Number(item.target_id));
        }
      });
      notifyChanged('outbox', 'sales', 'orders', 'customers');
    },

    async markRetry(item, message, nextAttemptAt, result) {
      const db = await getDb();
      await withWrite(db, async (tx) => {
        await tx.runAsync(
          "UPDATE outbox SET status = 'failed', attempts = attempts + 1, next_attempt_at = ?, last_error = ?, last_code = ?, http_status = ? WHERE id = ?",
          nextAttemptAt, message, result?.code ?? null, result?.http_status ?? null, item.id,
        );
        if (item.type === 'sale.create') await tx.runAsync("UPDATE sales SET sync_state = 'failed', message = ? WHERE mutation_id = ?", message, item.id);
      });
      notifyChanged('outbox', 'sales');
    },

    async cursors() {
      const db = await getDb();
      const rows = await db.getAllAsync<{ key: string; value: string }>("SELECT key, value FROM meta WHERE key LIKE 'cursor.%'");
      return Object.fromEntries(rows.map((r) => [r.key.slice(7), JSON.parse(r.value) as string | null]));
    },

    async applyPage(entity: string, page: EntityPage) {
      const upsert = UPSERT[entity];
      if (!upsert) return;
      const db = await getDb();
      const firstPage = !(await db.getFirstAsync('SELECT 1 FROM meta WHERE key = ?', `cursor.${entity}`));
      await withWrite(db, async (tx) => {
        for (const row of page.rows) {
          if (row.deleted) {
            if (DELETE[entity]) await tx.runAsync(DELETE[entity], Number(row.id));
          } else {
            await upsert(tx, row);
          }
        }
        await tx.runAsync(
          'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
          `cursor.${entity}`,
          JSON.stringify(page.cursor),
        );
      });
      if (page.rows.length) {
        notifyChanged(entity === 'units' ? 'products' : entity === 'stock' ? 'products' : entity);
        await onPulled?.(entity, page.rows, firstPage);
      }
    },
  };
}

/** A different branch: its stock, orders and sales come fresh from the server. */
export async function resetBranchData(): Promise<void> {
  const db = await getDb();
  await withWrite(db, async (tx) => {
    await tx.execAsync("DELETE FROM stock; DELETE FROM orders; DELETE FROM sales WHERE sync_state = 'synced';");
    for (const e of BRANCH_ENTITIES) await tx.runAsync('DELETE FROM meta WHERE key = ?', `cursor.${e}`);
  });
  notifyChanged('products', 'orders', 'sales');
}
