/**
 * Two-way sync, independent of React Native so it can be unit-tested.
 *
 *  1. PUSH: changes made on the phone (outbox, oldest first) go to the
 *     server in batches. Each has a UUID; the server applies each one
 *     exactly once and answers per change:
 *       applied / duplicate  → done, the local record gets the server id;
 *       rejected / conflict  → "needs attention": shown to the user to fix
 *                              or discard (never retried blindly);
 *       failed (5xx), or waiting for another change (DEPENDENCY)
 *                            → retried later with exponential backoff.
 *     A network error stops the push; nothing is lost.
 *  2. PULL: server changes since the last cursor of each kind of record,
 *     page by page, until nothing is left. Pushing first means the server's
 *     answer already includes this phone's own changes.
 */

export type MutationType =
  | 'sale.create'
  | 'customer.create'
  | 'customer.update'
  | 'customer.payment'
  | 'order.status'
  | 'order.complete'
  | 'notification.read'
  | 'notification.read_all';

export type OutboxItem = {
  id: string;
  seq: number;
  type: MutationType;
  target_id: string | number | null;
  payload: Record<string, unknown>;
  branch_id: number | null;
  created_at: string;
  base_updated_at: string | null;
  attempts: number;
};

export type PushStatus = 'applied' | 'duplicate' | 'rejected' | 'conflict' | 'failed';

export type PushResult = {
  id: string;
  status: PushStatus;
  result_id?: number;
  data?: Record<string, unknown>;
  message?: string;
  code?: string;
  http_status?: number;
  errors?: Record<string, string[]>;
};

export type EntityPage = { rows: Record<string, unknown>[]; cursor: string | null; has_more: boolean };

export type PullResponse = { server_time: string; entities: Record<string, EntityPage> };

export interface SyncTransport {
  push(items: OutboxItem[]): Promise<PushResult[]>;
  pull(cursors: Record<string, string | null>, only?: string[]): Promise<PullResponse>;
}

export interface SyncStore {
  /** Pending or retry-due changes, oldest first. `force` ignores the backoff wait. */
  dueMutations(now: number, force: boolean, limit: number): Promise<OutboxItem[]>;
  markApplied(item: OutboxItem, result: PushResult): Promise<void>;
  markAttention(item: OutboxItem, result: PushResult): Promise<void>;
  markRetry(item: OutboxItem, message: string, nextAttemptAt: number, result?: PushResult): Promise<void>;
  cursors(): Promise<Record<string, string | null>>;
  /** Save the rows and the new cursor together (one transaction). */
  applyPage(entity: string, page: EntityPage): Promise<void>;
}

export type SyncOptions = {
  force?: boolean;
  /** Pull only these kinds (e.g. after a push notification about an order). */
  only?: string[];
  pushOnly?: boolean;
  batchSize?: number;
  maxPullRounds?: number;
  now?: () => number;
  onProgress?: (p: SyncProgress) => void;
};

export type SyncProgress = { phase: 'push' | 'pull'; done: number; total?: number; entity?: string };

export type SyncReport = {
  ok: boolean;
  offline: boolean;
  unauthorized: boolean;
  pushed: number;
  applied: number;
  attention: number;
  retry: number;
  pulled: number;
  pulledByEntity: Record<string, number>;
  error: string | null;
};

/** Thrown by a transport when the server cannot be reached. */
export class OfflineError extends Error {
  readonly offline = true;
}

/** Thrown by a transport when the login is no longer valid. */
export class UnauthorizedError extends Error {
  readonly unauthorized = true;
}

const MIN_BACKOFF_MS = 15_000;
const MAX_BACKOFF_MS = 30 * 60_000;

/** 15 s, 30 s, 1 min, 2 min … capped at 30 minutes. */
export function backoffDelay(attempts: number): number {
  return Math.min(MAX_BACKOFF_MS, MIN_BACKOFF_MS * 2 ** Math.max(0, attempts - 1));
}

function isOffline(e: unknown): boolean {
  return !!e && typeof e === 'object' && 'offline' in e && (e as { offline: unknown }).offline === true;
}

function isUnauthorized(e: unknown): boolean {
  if (!e || typeof e !== 'object') return false;
  if ('unauthorized' in e && (e as { unauthorized: unknown }).unauthorized === true) return true;
  return 'status' in e && (e as { status: unknown }).status === 401;
}

function message(e: unknown): string {
  return e instanceof Error ? e.message : String(e);
}

export async function runSync(store: SyncStore, transport: SyncTransport, options: SyncOptions = {}): Promise<SyncReport> {
  const now = options.now ?? Date.now;
  const batchSize = options.batchSize ?? 50;
  const report: SyncReport = {
    ok: true,
    offline: false,
    unauthorized: false,
    pushed: 0,
    applied: 0,
    attention: 0,
    retry: 0,
    pulled: 0,
    pulledByEntity: {},
    error: null,
  };
  const fail = (e: unknown) => {
    report.ok = false;
    report.offline = isOffline(e);
    report.unauthorized = isUnauthorized(e);
    report.error = message(e);
    return report;
  };

  // ---------------------------------------------------------------- push
  const seen = new Set<string>();
  for (;;) {
    const due = (await store.dueMutations(now(), !!options.force, batchSize)).filter((m) => !seen.has(m.id));
    if (!due.length) break;
    due.forEach((m) => seen.add(m.id));

    let results: PushResult[];
    try {
      results = await transport.push(due);
    } catch (e) {
      if (!isOffline(e) && !isUnauthorized(e)) {
        // The whole request failed on the server: try these again later.
        for (const item of due) await store.markRetry(item, message(e), now() + backoffDelay(item.attempts + 1));
        report.retry += due.length;
      }
      return fail(e);
    }

    const byId = new Map(results.map((r) => [r.id, r]));
    let stop = false;
    for (const item of due) {
      const result = byId.get(item.id);
      report.pushed++;
      if (!result) {
        await store.markRetry(item, 'No answer from the server for this change.', now() + backoffDelay(item.attempts + 1));
        report.retry++;
        continue;
      }
      if (result.status === 'applied' || result.status === 'duplicate') {
        await store.markApplied(item, result);
        report.applied++;
      } else if ((result.status === 'rejected' || result.status === 'conflict') && result.code !== 'DEPENDENCY') {
        await store.markAttention(item, result);
        report.attention++;
      } else {
        // Server error, or it waits for a change that is not saved yet.
        await store.markRetry(item, result.message ?? 'Will try again.', now() + backoffDelay(item.attempts + 1), result);
        report.retry++;
        if (result.status === 'failed') stop = true;
      }
      options.onProgress?.({ phase: 'push', done: report.pushed });
    }
    // The server is struggling: leave the rest for the next sync.
    if (stop) break;
  }

  if (options.pushOnly) return report;

  // ---------------------------------------------------------------- pull
  const maxRounds = options.maxPullRounds ?? 200;
  let cursors = await store.cursors();
  let only = options.only;
  for (let round = 0; round < maxRounds; round++) {
    let response: PullResponse;
    try {
      response = await transport.pull(cursors, only);
    } catch (e) {
      return fail(e);
    }
    const more: string[] = [];
    for (const [entity, page] of Object.entries(response.entities)) {
      await store.applyPage(entity, page);
      report.pulled += page.rows.length;
      report.pulledByEntity[entity] = (report.pulledByEntity[entity] ?? 0) + page.rows.length;
      options.onProgress?.({ phase: 'pull', done: report.pulled, entity });
      if (page.has_more) more.push(entity);
    }
    if (!more.length) break;
    // Only the kinds that still have pages left.
    cursors = await store.cursors();
    only = more;
  }

  return report;
}
