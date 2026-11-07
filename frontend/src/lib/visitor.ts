/**
 * An anonymous id for this browser, kept in localStorage. Used to count
 * website visitors and to limit the shop assistant per visitor. It holds no
 * personal data; private windows simply get a new id.
 */
const KEY = "pp_vid";
let cached: string | null = null;

export function visitorId(): string | null {
  if (typeof window === "undefined") return null;
  if (cached) return cached;
  try {
    let id = window.localStorage.getItem(KEY);
    if (!id || !/^[a-zA-Z0-9-]{16,64}$/.test(id)) {
      id = (crypto.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`);
      window.localStorage.setItem(KEY, id);
    }
    cached = id;
  } catch {
    cached = cached ?? `tmp-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
  }
  return cached;
}
