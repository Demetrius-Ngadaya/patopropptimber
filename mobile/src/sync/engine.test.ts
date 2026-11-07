import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { backoffDelay, OfflineError, runSync, type EntityPage, type OutboxItem, type PushResult, type SyncStore, type SyncTransport } from './engine';

type Row = OutboxItem & { status: 'pending' | 'retry' | 'attention' | 'done'; next: number; message?: string };

function memoryStore(items: Partial<OutboxItem>[] = []) {
  const outbox: Row[] = items.map((m, i) => ({
    id: `m${i + 1}`,
    seq: i + 1,
    type: 'sale.create',
    target_id: null,
    payload: {},
    branch_id: 1,
    created_at: '2026-11-01T10:00:00Z',
    base_updated_at: null,
    attempts: 0,
    ...m,
    status: 'pending',
    next: 0,
  }));
  const cursors: Record<string, string | null> = {};
  const tables: Record<string, Map<unknown, Record<string, unknown>>> = {};
  const store: SyncStore = {
    async dueMutations(now, force, limit) {
      return outbox
        .filter((m) => m.status === 'pending' || (m.status === 'retry' && (force || m.next <= now)))
        .sort((a, b) => a.seq - b.seq)
        .slice(0, limit);
    },
    async markApplied(item) {
      Object.assign(outbox.find((m) => m.id === item.id)!, { status: 'done' });
    },
    async markAttention(item, result) {
      Object.assign(outbox.find((m) => m.id === item.id)!, { status: 'attention', message: result.message });
    },
    async markRetry(item, message, next) {
      const row = outbox.find((m) => m.id === item.id)!;
      Object.assign(row, { status: 'retry', message, next, attempts: row.attempts + 1 });
    },
    async cursors() {
      return { ...cursors };
    },
    async applyPage(entity, page: EntityPage) {
      tables[entity] ??= new Map();
      for (const row of page.rows) tables[entity].set(row.id, row);
      cursors[entity] = page.cursor;
    },
  };
  return { store, outbox, cursors, tables };
}

function transport(handlers: Partial<SyncTransport>, log: string[] = []): SyncTransport {
  return {
    async push(items) {
      log.push(`push:${items.map((i) => i.id).join(',')}`);
      return handlers.push ? handlers.push(items) : items.map((i) => ({ id: i.id, status: 'applied' as const }));
    },
    async pull(cursors, only) {
      log.push(`pull:${(only ?? ['all']).join(',')}`);
      return handlers.pull ? handlers.pull(cursors, only) : { server_time: '', entities: {} };
    },
  };
}

describe('runSync', () => {
  it('pushes in order before pulling, and applies every change once', async () => {
    const { store, outbox } = memoryStore([{}, {}, {}]);
    const log: string[] = [];
    const report = await runSync(store, transport({}, log));
    assert.deepEqual(log, ['push:m1,m2,m3', 'pull:all']);
    assert.equal(report.applied, 3);
    assert.ok(outbox.every((m) => m.status === 'done'));
  });

  it('sends large outboxes in batches', async () => {
    const { store } = memoryStore(Array.from({ length: 7 }, () => ({})));
    const log: string[] = [];
    await runSync(store, transport({}, log), { batchSize: 3 });
    assert.deepEqual(log.filter((l) => l.startsWith('push')), ['push:m1,m2,m3', 'push:m4,m5,m6', 'push:m7']);
  });

  it('keeps rejected changes for the user, retries server errors with backoff, and stops on a 5xx', async () => {
    const { store, outbox } = memoryStore([{}, {}, {}, {}]);
    const results: Record<string, PushResult> = {
      m1: { id: 'm1', status: 'rejected', message: 'Not enough stock', http_status: 422 },
      m2: { id: 'm2', status: 'conflict', message: 'Changed elsewhere' },
      m3: { id: 'm3', status: 'failed', message: 'Server error', http_status: 500 },
      m4: { id: 'm4', status: 'applied', result_id: 9 },
    };
    const log: string[] = [];
    const report = await runSync(store, transport({ push: async (items) => items.map((i) => results[i.id]) }, log), { now: () => 1000 });
    assert.deepEqual(outbox.map((m) => m.status), ['attention', 'attention', 'retry', 'done']);
    assert.equal(outbox[2].next, 1000 + 15_000);
    assert.equal(report.attention, 2);
    assert.equal(report.retry, 1);
    // A failed batch means the pull still runs (the server answered).
    assert.ok(log.includes('pull:all'));
  });

  it('retries a change that waits for another one (DEPENDENCY) instead of asking the user', async () => {
    const { store, outbox } = memoryStore([{}]);
    await runSync(store, transport({ push: async () => [{ id: 'm1', status: 'rejected', code: 'DEPENDENCY' }] }), { now: () => 0 });
    assert.equal(outbox[0].status, 'retry');
  });

  it('waits for the backoff unless the user taps Sync now', async () => {
    const { store, outbox } = memoryStore([{}]);
    outbox[0].status = 'retry';
    outbox[0].next = 50_000;
    const log: string[] = [];
    await runSync(store, transport({}, log), { now: () => 10_000 });
    assert.deepEqual(log, ['pull:all']);
    await runSync(store, transport({}, log), { now: () => 10_000, force: true });
    assert.equal(outbox[0].status, 'done');
  });

  it('stops without losing anything when the phone is offline', async () => {
    const { store, outbox } = memoryStore([{}, {}]);
    const report = await runSync(
      store,
      transport({
        push: async () => {
          throw new OfflineError('No internet connection.');
        },
      }),
    );
    assert.equal(report.ok, false);
    assert.equal(report.offline, true);
    assert.ok(outbox.every((m) => m.status === 'pending' && m.attempts === 0));
  });

  it('reports an expired login so the app can ask to sign in again', async () => {
    const { store } = memoryStore([{}]);
    const report = await runSync(
      store,
      transport({
        push: async () => {
          throw Object.assign(new Error('Unauthenticated.'), { status: 401 });
        },
      }),
    );
    assert.equal(report.unauthorized, true);
  });

  it('pulls page after page, only for the kinds that have more', async () => {
    const { store, tables, cursors } = memoryStore();
    const pages: Record<string, EntityPage[]> = {
      products: [
        { rows: [{ id: 1 }, { id: 2 }], cursor: 'p1', has_more: true },
        { rows: [{ id: 3 }], cursor: 'p2', has_more: false },
      ],
      customers: [{ rows: [{ id: 7 }], cursor: 'c1', has_more: false }],
    };
    const calls: (string[] | undefined)[] = [];
    const report = await runSync(
      store,
      transport({
        pull: async (_c, only) => {
          calls.push(only);
          const kinds = only ?? Object.keys(pages);
          return { server_time: '', entities: Object.fromEntries(kinds.map((k) => [k, pages[k].shift()!])) };
        },
      }),
    );
    assert.deepEqual(calls, [undefined, ['products']]);
    assert.equal(report.pulled, 4);
    assert.equal(tables.products.size, 3);
    assert.deepEqual(cursors, { products: 'p2', customers: 'c1' });
  });

  it('backs off exponentially up to 30 minutes', () => {
    assert.deepEqual([1, 2, 3, 4].map(backoffDelay), [15_000, 30_000, 60_000, 120_000]);
    assert.equal(backoffDelay(30), 30 * 60_000);
  });
});
