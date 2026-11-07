import * as Crypto from 'expo-crypto';
import type { SQLiteDatabase } from 'expo-sqlite';
import { getDb, notifyChanged, withWrite } from './database';
import { runtime } from '../lib/runtime';
import { t } from '../i18n';
import type { MutationType } from '../sync/engine';

/**
 * The outbox: every change made on this phone, waiting to be sent. Writes
 * the local copy and the outbox entry in ONE transaction, so the screen and
 * the queue can never disagree, even if the app is killed in between.
 */

export type OutboxStatus = 'pending' | 'failed' | 'attention' | 'done';

export type OutboxEntry = {
  seq: number;
  id: string;
  type: MutationType;
  target_id: string | null;
  payload: Record<string, unknown>;
  created_at: string;
  status: OutboxStatus;
  attempts: number;
  next_attempt_at: number;
  last_error: string | null;
  last_code: string | null;
  http_status: number | null;
  errors: Record<string, string[]> | null;
  entity_key: string | null;
  summary: string | null;
  done_at: string | null;
};

export type NewMutation = {
  type: MutationType;
  payload: Record<string, unknown>;
  targetId?: string | number | null;
  baseUpdatedAt?: string | null;
  entityKey?: string | null;
  summary?: string;
  id?: string;
};

export function newId(): string {
  return Crypto.randomUUID();
}

export async function enqueue(tx: SQLiteDatabase, m: NewMutation): Promise<string> {
  const { userId, branchId } = runtime.get();
  if (!userId) throw new Error(t('Not signed in.'));
  const id = m.id ?? newId();
  await tx.runAsync(
    `INSERT INTO outbox (id, user_id, branch_id, type, target_id, payload, base_updated_at, created_at, status, entity_key, summary)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)`,
    id,
    userId,
    branchId,
    m.type,
    m.targetId === undefined || m.targetId === null ? null : String(m.targetId),
    JSON.stringify(m.payload),
    m.baseUpdatedAt ?? null,
    new Date().toISOString(),
    m.entityKey ?? null,
    m.summary ?? null,
  );
  return id;
}

/** Run local writes plus their outbox entries atomically, then tell the screens and the sync. */
export async function transaction<T>(work: (tx: SQLiteDatabase) => Promise<T>, tables: string[]): Promise<T> {
  const db = await getDb();
  let result!: T;
  await withWrite(db, async (tx) => {
    result = await work(tx);
  });
  notifyChanged('outbox', ...tables);
  return result;
}

function toEntry(r: Record<string, unknown>): OutboxEntry {
  let result: { errors?: Record<string, string[]> } = {};
  try {
    result = r.result ? JSON.parse(String(r.result)) : {};
  } catch {
    // ignore
  }
  return {
    seq: Number(r.seq),
    id: String(r.id),
    type: r.type as MutationType,
    target_id: (r.target_id as string) ?? null,
    payload: JSON.parse(String(r.payload ?? '{}')),
    created_at: String(r.created_at),
    status: r.status as OutboxStatus,
    attempts: Number(r.attempts ?? 0),
    next_attempt_at: Number(r.next_attempt_at ?? 0),
    last_error: (r.last_error as string) ?? null,
    last_code: (r.last_code as string) ?? null,
    http_status: r.http_status === null || r.http_status === undefined ? null : Number(r.http_status),
    errors: result.errors ?? null,
    entity_key: (r.entity_key as string) ?? null,
    summary: (r.summary as string) ?? null,
    done_at: (r.done_at as string) ?? null,
  };
}

export async function listOutbox(statuses: OutboxStatus[], limit = 200): Promise<OutboxEntry[]> {
  const db = await getDb();
  const { userId } = runtime.get();
  const rows = await db.getAllAsync<Record<string, unknown>>(
    `SELECT * FROM outbox WHERE user_id = ? AND status IN (${statuses.map(() => '?').join(',')}) ORDER BY seq ${statuses.includes('done') ? 'DESC' : 'ASC'} LIMIT ?`,
    userId ?? 0,
    ...statuses,
    limit,
  );
  return rows.map(toEntry);
}

export type OutboxCounts = { waiting: number; attention: number; otherUsers: number };

export async function outboxCounts(): Promise<OutboxCounts> {
  const db = await getDb();
  const { userId } = runtime.get();
  const row = await db.getFirstAsync<{ waiting: number; attention: number; other: number }>(
    `SELECT
       SUM(CASE WHEN user_id = ? AND status IN ('pending','failed') THEN 1 ELSE 0 END) AS waiting,
       SUM(CASE WHEN user_id = ? AND status = 'attention' THEN 1 ELSE 0 END) AS attention,
       SUM(CASE WHEN user_id != ? AND status != 'done' THEN 1 ELSE 0 END) AS other
     FROM outbox`,
    userId ?? 0,
    userId ?? 0,
    userId ?? 0,
  );
  return { waiting: Number(row?.waiting ?? 0), attention: Number(row?.attention ?? 0), otherUsers: Number(row?.other ?? 0) };
}

/** Send again now (after fixing the cause, e.g. stock arrived). */
export async function retryEntry(id: string): Promise<void> {
  await transaction(async (tx) => {
    await tx.runAsync("UPDATE outbox SET status = 'pending', next_attempt_at = 0, last_error = NULL, last_code = NULL WHERE id = ?", id);
    await tx.runAsync("UPDATE sales SET sync_state = 'pending', message = NULL WHERE mutation_id = ?", id);
  }, ['sales']);
}

/**
 * Give up on a change: undo what it did on this phone, and give up on the
 * changes that depend on it (e.g. a sale for a customer added offline).
 */
export async function discardEntry(id: string): Promise<number> {
  return transaction(async (tx) => {
    const ids = [id];
    for (let i = 0; i < ids.length; i++) {
      const dependents = await tx.getAllAsync<{ id: string }>(
        "SELECT id FROM outbox WHERE status != 'done' AND (payload LIKE ? OR target_id = ?)",
        `%@ref:${ids[i]}%`,
        `@ref:${ids[i]}`,
      );
      dependents.forEach((d) => !ids.includes(d.id) && ids.push(d.id));
    }
    for (const mid of ids) {
      const row = await tx.getFirstAsync<Record<string, unknown>>('SELECT * FROM outbox WHERE id = ?', mid);
      if (!row) continue;
      const entry = toEntry(row);
      if (entry.type === 'sale.create') await tx.runAsync('DELETE FROM sales WHERE mutation_id = ? AND id IS NULL', mid);
      if (entry.type === 'customer.create') await tx.runAsync('DELETE FROM customers WHERE key = ? AND id IS NULL', entry.entity_key ?? '');
      if (entry.type === 'customer.update') await tx.runAsync('UPDATE customers SET pending = 0 WHERE key = ?', entry.entity_key ?? '');
      if (entry.type === 'order.status' || entry.type === 'order.complete') {
        await tx.runAsync('UPDATE orders SET pending_status = NULL WHERE id = ?', Number(entry.target_id));
      }
      await tx.runAsync('DELETE FROM outbox WHERE id = ?', mid);
    }
    return ids.length;
  }, ['sales', 'customers', 'orders']);
}

/** Keep a week of sent changes for the sync history, then forget them. */
export async function pruneDone(): Promise<void> {
  const db = await getDb();
  const weekAgo = new Date(Date.now() - 7 * 86400_000).toISOString();
  await db.runAsync("DELETE FROM outbox WHERE status = 'done' AND done_at < ?", weekAgo);
  await db.runAsync('DELETE FROM sync_log WHERE id NOT IN (SELECT id FROM sync_log ORDER BY id DESC LIMIT 100)');
}
