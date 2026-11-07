import { Platform } from 'react-native';
import * as SQLite from 'expo-sqlite';
import { MIGRATIONS } from './schema';

/**
 * The phone's own copy of the shop: products, stock, customers, orders,
 * sales, notifications, plus the outbox of changes waiting to be sent.
 * Everything the app shows is read from here, so it works offline.
 */
let db: SQLite.SQLiteDatabase | null = null;
let opening: Promise<SQLite.SQLiteDatabase> | null = null;

const listeners = new Set<(tables: string[]) => void>();

export async function getDb(): Promise<SQLite.SQLiteDatabase> {
  if (db) return db;
  opening ??= (async () => {
    const database = await SQLite.openDatabaseAsync('patopro.db');
    await database.execAsync('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    await migrate(database);
    db = database;
    return database;
  })();
  return opening;
}

/**
 * A write transaction. On phones it runs on its own connection, so a sync in
 * the background and a sale at the till never interleave inside one another.
 * (The browser preview only has the shared-connection kind.)
 */
export async function withWrite(database: SQLite.SQLiteDatabase, work: (tx: SQLite.SQLiteDatabase) => Promise<void>): Promise<void> {
  if (Platform.OS === 'web') return database.withTransactionAsync(() => work(database));
  return database.withExclusiveTransactionAsync(work);
}

async function migrate(database: SQLite.SQLiteDatabase) {
  const row = await database.getFirstAsync<{ user_version: number }>('PRAGMA user_version');
  let version = row?.user_version ?? 0;
  for (const migration of MIGRATIONS) {
    if (migration.version <= version) continue;
    await withWrite(database, async (tx) => {
      await tx.execAsync(migration.sql);
      await tx.execAsync(`PRAGMA user_version = ${migration.version}`);
    });
    version = migration.version;
  }
}

/** Screens re-read when a table they show has changed. */
export function onTablesChanged(fn: (tables: string[]) => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function notifyChanged(...tables: string[]) {
  listeners.forEach((fn) => {
    try {
      fn(tables);
    } catch {
      // A screen's listener must never break a write.
    }
  });
}

/** Erase everything (sign out of a different user, or "reset local data"). */
export async function wipeLocalData(keepOutbox = false) {
  const database = await getDb();
  await withWrite(database, async (tx) => {
    await tx.execAsync(`
      DELETE FROM categories; DELETE FROM products; DELETE FROM product_units; DELETE FROM stock;
      DELETE FROM customers; DELETE FROM orders; DELETE FROM sales; DELETE FROM notifications;
      DELETE FROM sync_log; DELETE FROM meta WHERE key LIKE 'cursor.%';
      ${keepOutbox ? '' : 'DELETE FROM outbox;'}
    `);
  });
  notifyChanged('*');
}

export const sqlBool = (v: unknown) => (v ? 1 : 0);
