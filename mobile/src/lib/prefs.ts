import { create } from 'zustand';
import { getMeta, setMeta } from '../db/meta';
import type { AlertMode, NotificationCategory } from './types';

/**
 * This phone's settings (Settings screen). Saved in the local database;
 * the notification part is also sent to the server, which shapes each push
 * (sound / vibration channel, voice) for this phone.
 */
export type QuietHours = { enabled: boolean; from: string; to: string };

export type Prefs = {
  locale: 'en' | 'sw' | null;
  theme: 'system' | 'light' | 'dark';
  notifications: {
    categories: Record<NotificationCategory, AlertMode>;
    quietHours: QuietHours;
    voiceLanguage: 'auto' | 'sw' | 'en';
    voiceRate: number;
  };
  sync: { auto: boolean; intervalMinutes: number; wifiOnly: boolean; background: boolean };
  security: { biometric: boolean; pin: boolean; lockAfterMinutes: number };
  pos: { haptics: boolean; sounds: boolean; confirmBeforeCheckout: boolean };
};

export const DEFAULT_ALERTS: Record<NotificationCategory, AlertMode> = {
  orders: { enabled: true, voice: true, sound: true, vibrate: true },
  stock: { enabled: true, voice: false, sound: true, vibrate: true },
  money: { enabled: true, voice: false, sound: true, vibrate: true },
  team: { enabled: true, voice: false, sound: true, vibrate: true },
  general: { enabled: true, voice: false, sound: true, vibrate: true },
};

export const DEFAULT_PREFS: Prefs = {
  locale: null,
  theme: 'system',
  notifications: {
    categories: DEFAULT_ALERTS,
    quietHours: { enabled: false, from: '22:00', to: '06:00' },
    voiceLanguage: 'auto',
    voiceRate: 1,
  },
  sync: { auto: true, intervalMinutes: 2, wifiOnly: false, background: true },
  security: { biometric: false, pin: false, lockAfterMinutes: 1 },
  pos: { haptics: true, sounds: true, confirmBeforeCheckout: false },
};

type PrefsState = Prefs & {
  loaded: boolean;
  load: () => Promise<Prefs>;
  update: (patch: DeepPartial<Prefs>) => Promise<void>;
  setAlert: (category: NotificationCategory, mode: Partial<AlertMode>) => Promise<void>;
};

type DeepPartial<T> = { [K in keyof T]?: T[K] extends object ? DeepPartial<T[K]> : T[K] };

function merge<T>(base: T, patch: DeepPartial<T> | undefined): T {
  if (!patch) return base;
  const out: Record<string, unknown> = { ...(base as Record<string, unknown>) };
  for (const [k, v] of Object.entries(patch)) {
    const current = out[k];
    out[k] = v && typeof v === 'object' && !Array.isArray(v) && current && typeof current === 'object' ? merge(current, v as never) : v;
  }
  return out as T;
}

const pick = (s: PrefsState): Prefs => ({
  locale: s.locale,
  theme: s.theme,
  notifications: s.notifications,
  sync: s.sync,
  security: s.security,
  pos: s.pos,
});

const subscribers = new Set<(p: Prefs, previous: Prefs) => void>();

/** Notification settings changed: re-register the phone with the server. */
export function onPrefsChanged(fn: (p: Prefs, previous: Prefs) => void): () => void {
  subscribers.add(fn);
  return () => subscribers.delete(fn);
}

export const usePrefs = create<PrefsState>((set, get) => ({
  ...DEFAULT_PREFS,
  loaded: false,
  async load() {
    const stored = await getMeta<DeepPartial<Prefs>>('prefs', {});
    const prefs = merge(DEFAULT_PREFS, stored);
    set({ ...prefs, loaded: true });
    return prefs;
  },
  async update(patch) {
    const previous = pick(get());
    const next = merge(previous, patch);
    set(next);
    await setMeta('prefs', next);
    subscribers.forEach((fn) => fn(next, previous));
  },
  async setAlert(category, mode) {
    await get().update({ notifications: { categories: { [category]: mode } as DeepPartial<Record<NotificationCategory, AlertMode>> } });
  },
}));

/** For code outside React (tasks, notification handlers). */
export async function currentPrefs(): Promise<Prefs> {
  const state = usePrefs.getState();
  return state.loaded ? pick(state) : state.load();
}
