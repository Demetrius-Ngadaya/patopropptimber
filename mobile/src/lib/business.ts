import { getMeta, setMeta } from '../db/meta';
import { checkServer, normaliseServer } from './server';

/**
 * Business codes: one app for every business. The person types their business code
 * once ("dablos"); the app looks it up in the list on shezoni.com and from then on
 * talks to that business's own server. The list only maps codes to addresses.
 */
export const DIRECTORY_URL: string = process.env.EXPO_PUBLIC_DIRECTORY_URL || 'https://shezoni.com/patopro/directory.json';

export type Business = { code: string; name: string; api: string };

export const cleanCode = (code: string) => code.trim().toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9-]/g, '');

/** The business this phone uses (null: the one built into the app). */
export const savedBusiness = () => getMeta<Business | null>('business', null);
export const saveBusiness = (b: Business | null) => setMeta('business', b);

export type LookupResult = { ok: true; business: Business } | { ok: false; reason: 'not_found' | 'offline' | 'server_down' };

/** Finds a code in the list, and checks that the business's server answers. */
export async function lookupBusiness(code: string): Promise<LookupResult> {
  const wanted = cleanCode(code);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 10000);
  let list: Record<string, { name?: string; api?: string }> | null = null;
  try {
    const res = await fetch(`${DIRECTORY_URL}?t=${Date.now()}`, { signal: controller.signal, headers: { Accept: 'application/json' } });
    if (res.ok) {
      const body = (await res.json()) as { businesses?: Record<string, { name?: string; api?: string }> };
      list = body.businesses ?? null;
    }
  } catch {
    list = null;
  } finally {
    clearTimeout(timer);
  }
  if (!list) return { ok: false, reason: 'offline' };
  const entry = list[wanted];
  if (!entry?.api) return { ok: false, reason: 'not_found' };
  const api = normaliseServer(entry.api);
  if (!(await checkServer(api))) return { ok: false, reason: 'server_down' };
  return { ok: true, business: { code: wanted, name: entry.name || wanted, api } };
}
