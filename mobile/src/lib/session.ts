import Constants from 'expo-constants';
import * as Device from 'expo-device';
import { create } from 'zustand';
import { api, ApiError } from './api';
import { runtime } from './runtime';
import { secure, type StoredSession } from './secure';
import { setCurrency } from './format';
import { currentPrefs } from './prefs';
import type { BusinessSettings, User } from './types';
import { getMeta, setMeta } from '../db/meta';
import { wipeLocalData } from '../db/database';
import { deviceLocale, useLocale } from '../i18n';

/**
 * Who is signed in, on which branch, against which server. Survives restarts
 * (keychain) so a cashier can open the app and sell with no internet.
 */
export type SessionStatus = 'booting' | 'signedOut' | 'twoFactor' | 'ready';

type LoginResult = { token: string; expires_at: string | null; user: User };

type SessionState = {
  status: SessionStatus;
  user: User | null;
  settings: BusinessSettings;
  branchId: number | null;
  apiUrl: string;
  challenge: string | null;
  /** The server ended the login (expired / revoked): sign in again, offline work is kept. */
  expired: boolean;
  boot: () => Promise<void>;
  setServer: (url: string) => Promise<void>;
  login: (login: string, password: string) => Promise<'ok' | 'two_factor'>;
  verifyTwoFactor: (code: string) => Promise<void>;
  refresh: () => Promise<void>;
  setBranch: (id: number) => Promise<void>;
  logout: () => Promise<void>;
  can: (permission: string) => boolean;
};

export const DEFAULT_API_URL: string =
  process.env.EXPO_PUBLIC_API_URL || ((Constants.expoConfig?.extra as { apiUrl?: string } | undefined)?.apiUrl ?? '');

export function normaliseServer(url: string): string {
  let u = url.trim().replace(/\/+$/, '').replace(/\/api(\/v1)?$/, '');
  if (u && !/^https?:\/\//i.test(u)) u = `https://${u}`;
  return u;
}

function applyLocale(user: User | null, pref: 'en' | 'sw' | null) {
  const locale = pref ?? user?.locale ?? deviceLocale();
  useLocale.getState().setLocale(locale);
  runtime.set({ locale });
}

/** Hooks other modules attach (device registration, sync), to keep imports one-way. */
type Hook = (event: 'signedIn' | 'signedOut' | 'branchChanged' | 'refreshed') => void | Promise<void>;
const hooks = new Set<Hook>();
export function onSession(fn: Hook): () => void {
  hooks.add(fn);
  return () => hooks.delete(fn);
}
const emit = async (e: Parameters<Hook>[0]) => {
  for (const fn of hooks) {
    try {
      await fn(e);
    } catch {
      // A hook failing must not break signing in.
    }
  }
};

export const useSession = create<SessionState>((set, get) => {
  const persist = async () => {
    const { user, branchId } = get();
    const token = runtime.get().token;
    if (!user || !token) return;
    const previous = await secure.session();
    await secure.saveSession({ token, expiresAt: previous?.expiresAt ?? null, user, branchId });
  };

  const finish = async (data: LoginResult) => {
    const lastUser = await getMeta<number | null>('lastUserId', null);
    if (lastUser !== null && lastUser !== data.user.id) {
      // Another person on this phone: they must not see the previous user's data.
      await wipeLocalData(true);
    }
    await setMeta('lastUserId', data.user.id);
    const branchId = data.user.branch?.id ?? data.user.branches[0]?.id ?? null;
    const stored: StoredSession = { token: data.token, expiresAt: data.expires_at, user: data.user, branchId };
    await secure.saveSession(stored);
    runtime.set({ token: data.token, userId: data.user.id, branchId });
    applyLocale(data.user, (await currentPrefs()).locale);
    set({ status: 'ready', user: data.user, branchId, challenge: null, expired: false });
    await get().refresh();
    await emit('signedIn');
  };

  return {
    status: 'booting',
    user: null,
    settings: {},
    branchId: null,
    apiUrl: DEFAULT_API_URL,
    challenge: null,
    expired: false,

    async boot() {
      const [prefs, server, installationId, stored, settings] = await Promise.all([
        currentPrefs(),
        secure.server(),
        secure.installationId(),
        secure.session(),
        getMeta<BusinessSettings>('settings', {}),
      ]);
      const apiUrl = server || DEFAULT_API_URL;
      runtime.set({ apiUrl, installationId });
      setCurrency(settings.currency);
      runtime.onUnauthorized(() => {
        if (get().status !== 'ready') return;
        runtime.set({ token: null });
        secure.saveSession(null);
        set({ status: 'signedOut', expired: true });
        emit('signedOut');
      });

      const valid = stored && (!stored.expiresAt || new Date(stored.expiresAt).getTime() > Date.now());
      if (!valid || !stored) {
        applyLocale(null, prefs.locale);
        set({ status: 'signedOut', apiUrl, settings, expired: !!stored });
        return;
      }
      runtime.set({ token: stored.token, userId: stored.user.id, branchId: stored.branchId });
      applyLocale(stored.user, prefs.locale);
      // Ready straight away from the phone's copy: the network refresh happens in the background.
      set({ status: 'ready', user: stored.user, branchId: stored.branchId, apiUrl, settings });
      get().refresh().catch(() => undefined);
      emit('signedIn');
    },

    async setServer(url) {
      const apiUrl = normaliseServer(url);
      await secure.saveServer(apiUrl || null);
      runtime.set({ apiUrl: apiUrl || DEFAULT_API_URL });
      set({ apiUrl: apiUrl || DEFAULT_API_URL });
    },

    async login(login, password) {
      const deviceName = [Device.manufacturer, Device.modelName].filter(Boolean).join(' ') || 'Phone';
      const res = await api.post<LoginResult & { two_factor_required?: boolean; challenge?: string }>(
        'auth/login',
        { login, password, client: 'mobile', device_name: `PatoPro app · ${deviceName}` },
        { auth: false },
      );
      if (res.data.two_factor_required && res.data.challenge) {
        set({ status: 'twoFactor', challenge: res.data.challenge });
        return 'two_factor';
      }
      if (res.data.user.type !== 'staff') {
        throw new ApiError('The PatoPro app is for shop staff. Customers can order on the online shop.', 403, 'NOT_STAFF');
      }
      await finish(res.data);
      return 'ok';
    },

    async verifyTwoFactor(code) {
      const challenge = get().challenge;
      if (!challenge) throw new ApiError('Sign in again.', 422);
      const res = await api.post<LoginResult>('auth/two-factor/verify', { challenge, code, device_name: 'PatoPro app' }, { auth: false });
      await finish(res.data);
    },

    /** Latest user, permissions and business settings (silently skipped offline). */
    async refresh() {
      try {
        const res = await api.get<{ user: User; settings: BusinessSettings }>('admin/mobile/bootstrap');
        const { user, settings } = res.data;
        setCurrency(settings.currency);
        await setMeta('settings', settings);
        const branchId = get().branchId && (user.all_branches ? user.branches : user.branch ? [user.branch] : []).some((b) => b.id === get().branchId) ? get().branchId : user.branch?.id ?? user.branches[0]?.id ?? null;
        runtime.set({ userId: user.id, branchId });
        set({ user, settings, branchId });
        applyLocale(user, (await currentPrefs()).locale);
        await persist();
        await emit('refreshed');
      } catch (e) {
        if (!(e instanceof ApiError) || !e.offline) throw e;
      }
    },

    async setBranch(id) {
      if (id === get().branchId) return;
      runtime.set({ branchId: id });
      set({ branchId: id });
      await persist();
      await emit('branchChanged');
    },

    async logout() {
      const { installationId } = runtime.get();
      // Best effort: the phone forgets the login even when offline.
      if (installationId) await api.delete(`admin/mobile/devices/${installationId}`, { timeoutMs: 5000 }).catch(() => undefined);
      await api.post('auth/logout', {}, { timeoutMs: 5000 }).catch(() => undefined);
      await emit('signedOut');
      await secure.saveSession(null);
      runtime.set({ token: null });
      set({ status: 'signedOut', user: null, challenge: null, expired: false });
    },

    can(permission) {
      const user = get().user;
      if (!user) return false;
      if (user.role?.is_super_admin || user.role?.is_developer) return true;
      return permission.split('|').some((p) => user.permissions.includes(p));
    },
  };
});

export const useCan = (permission: string) => useSession((s) => s.can(permission) && !!s.user);
