import { api, ApiError } from '../lib/api';
import { runtime } from '../lib/runtime';
import { OfflineError, UnauthorizedError, type OutboxItem, type PullResponse, type PushResult, type SyncTransport } from './engine';

/** The sync engine talking to /api/v1/admin/mobile/sync/*. */
function translate(e: unknown): never {
  if (e instanceof ApiError) {
    if (e.offline) throw new OfflineError(e.message);
    if (e.status === 401) throw new UnauthorizedError(e.message);
  }
  throw e;
}

export const httpTransport: SyncTransport = {
  async push(items: OutboxItem[]): Promise<PushResult[]> {
    try {
      const res = await api.post<{ results: PushResult[] }>(
        'admin/mobile/sync/push',
        {
          installation_id: runtime.get().installationId,
          mutations: items.map((m) => ({
            id: m.id,
            type: m.type,
            target_id: m.target_id,
            payload: m.payload,
            branch_id: m.branch_id,
            created_at: m.created_at,
            base_updated_at: m.base_updated_at,
          })),
        },
        { timeoutMs: 60000 },
      );
      return res.data.results;
    } catch (e) {
      return translate(e);
    }
  },

  async pull(cursors, only): Promise<PullResponse> {
    try {
      const res = await api.get<PullResponse>(
        'admin/mobile/sync/pull',
        {
          cursors: Object.fromEntries(Object.entries(cursors).filter(([, v]) => v !== null)) as Record<string, string>,
          only,
          installation_id: runtime.get().installationId,
        },
        { timeoutMs: 45000 },
      );
      return res.data;
    } catch (e) {
      return translate(e);
    }
  },
};
