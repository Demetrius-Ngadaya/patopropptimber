import Constants from 'expo-constants';
import * as Device from 'expo-device';
import NetInfo from '@react-native-community/netinfo';
import { create } from 'zustand';
import { api, ApiError } from './api';
import { runtime } from './runtime';
import { secure, type StoredSession } from './secure';
import { setCurrency } from './format';
import { currentPrefs } from './prefs';
import type { BusinessSettings, User } from './types';
import { getMeta, setMeta } from '../db/meta';
import { wipeLocalData } from '../db/database';
import { deviceLocale, t, useLocale } from '../i18n';
import { checkServer, normaliseServer, reportInstall } from './server';

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
  /** Signed in on this phone without internet: the real sign-in finishes when the internet returns. */
  offline: boolean;
  boot: () => Promise<void>;
  setServer: (url: string, source?: 'manual' | 'remote') => Promise<void>;
  login: (login: string, password: string) => Promise<'ok' | 'two_factor'>;
  verifyTwoFactor: (code: string) => Promise<void>;
  refresh: () => Promise<void>;
  /** A customer created an account in the app (signed in straight away). */
  register: (data: Record<string, unknown>) => Promise<void>;
  setBranch: (id: number) => Promise<void>;
  logout: () => Promise<void>;
  can: (permission: string) => boolean;
};

export const DEFAULT_API_URL: string =
  process.env.EXPO_PUBLIC_API_URL || ((Constants.expoConfig?.extra as { apiUrl?: string } | undefined)?.apiUrl ?? '');

export { normaliseServer } from './server';

/**
 * The Software Engineer can move every app to another server from the website
 * (System → Mobile app server). The app follows only after the new address answers.
 */
async function followRemoteServer(settings: BusinessSettings, current: string, apply: (url: string) => Promise<void>) {
  const wanted = normaliseServer(String((settings as Record<string, unknown>).mobile_api_url ?? ''));
  const source = await getMeta<'remote' | 'manual' | null>('serverSource', null);
  if (wanted) {
    if (wanted === current || (await getMeta<string | null>('serverRejected', null)) === wanted) return;
    if (await checkServer(wanted)) {
      await apply(wanted);
      await setMeta('serverSource', 'remote');
    } else {
      await setMeta('serverRejected', wanted); // try again only when the engineer saves another address
    }
  } else if (source === 'remote' && current !== DEFAULT_API_URL) {
    await apply(''); // the engineer cleared it: back to the address built into the app
    await setMeta('serverSource', null);
  }
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

  // Kept in memory only (never saved): finishes an offline sign-in when the internet returns,
  // and remembers the password's hash after the two-step code.
  let pending: { login: string; password: string } | null = null;
  let stopWatching: (() => void) | null = null;

  const finish = async (data: LoginResult) => {
    const lastUser = await getMeta<number | null>('lastUserId', null);
    if (lastUser !== null && lastUser !== data.user.id) {
      // Another person on this phone: they must not see the previous user's data.
      await wipeLocalData(true);
      await secure.forgetOfflineLogin();
    }
    await setMeta('lastUserId', data.user.id);
    const branchId = data.user.branch?.id ?? data.user.branches?.[0]?.id ?? null;
    const stored: StoredSession = { token: data.token, expiresAt: data.expires_at, user: data.user, branchId };
    await secure.saveSession(stored);
    if (pending && data.user.type === 'staff') {
      await secure.rememberOfflineLogin(pending.login, pending.password, data.user, branchId).catch(() => undefined);
    }
    pending = null;
    stopWatching?.();
    stopWatching = null;
    runtime.set({ token: data.token, userId: data.user.id, userType: data.user.type, branchId });
    applyLocale(data.user, (await currentPrefs()).locale);
    const wasOffline = get().offline;
    set({ status: 'ready', user: data.user, branchId, challenge: null, expired: false, offline: false });
    await get().refresh().catch(() => undefined);
    // After an offline start the app is already running: only the sync needs a nudge.
    await emit(wasOffline ? 'refreshed' : 'signedIn');
  };

  /** Sign in from the copy kept on this phone (no internet). */
  const signInOffline = async (login: string, password: string) => {
    const check = await secure.checkOfflineLogin(login, password);
    if (check.result === 'unknown') {
      throw new ApiError(t('No internet. The first sign-in on this phone needs internet; after that you can sign in without it.'), 0, 'OFFLINE_FIRST', null, true);
    }
    if (check.result === 'stale') {
      throw new ApiError(t('No internet, and this phone has not checked your password with the server for over 30 days. Connect to the internet once to sign in.'), 0, 'OFFLINE_STALE', null, true);
    }
    if (check.result !== 'ok') {
      throw new ApiError(t('Wrong username or password. (No internet: checked against your last sign-in on this phone.)'), 401, 'OFFLINE_WRONG');
    }
    const { user, branchId } = check.login;
    if ((await getMeta<number | null>('lastUserId', null)) !== user.id) {
      throw new ApiError(t('No internet. The first sign-in on this phone needs internet; after that you can sign in without it.'), 0, 'OFFLINE_FIRST', null, true);
    }
    pending = { login, password };
    runtime.set({ token: null, userId: user.id, userType: user.type, branchId });
    applyLocale(user, (await currentPrefs()).locale);
    set({ status: 'ready', user, branchId, challenge: null, expired: false, offline: true });
    await emit('signedIn');
    watchForInternet();
  };

  /** Offline sign-in: as soon as the internet is back, sign in with the server for real. */
  const watchForInternet = () => {
    stopWatching?.();
    let trying = false;
    const attempt = async () => {
      if (trying || !pending || !get().offline) return;
      trying = true;
      try {
        await get().login(pending.login, pending.password);
      } catch (e) {
        if (e instanceof ApiError && !e.offline) {
          // The password changed, or the account was stopped: sign in again (offline work is kept).
          pending = null;
          stopWatching?.();
          stopWatching = null;
          await secure.forgetOfflineLogin();
          runtime.set({ token: null });
          set({ status: 'signedOut', offline: false, expired: true, challenge: null });
          await emit('signedOut');
        }
      } finally {
        trying = false;
      }
    };
    const unsubscribe = NetInfo.addEventListener((net) => {
      if (net.isConnected && net.isInternetReachable !== false) attempt();
    });
    const timer = setInterval(attempt, 60_000);
    stopWatching = () => {
      unsubscribe();
      clearInterval(timer);
    };
  };

  return {
    status: 'booting',
    user: null,
    settings: {},
    branchId: null,
    apiUrl: DEFAULT_API_URL,
    challenge: null,
    expired: false,
    offline: false,

    async boot() {
      const [prefs, server, installationId, stored, settings] = await Promise.all([
        currentPrefs(),
        secure.server(),
        secure.installationId(),
        secure.session(),
        getMeta<BusinessSettings>('settings', {}),
      ]);
      const apiUrl = server || DEFAULT_API_URL;
      runtime.set({ apiUrl, installationId, locale: prefs.locale ?? deviceLocale() });
      setCurrency(settings.currency);
      // For the "Mobile app users" report, and to follow a server move by the engineer
      // (both in the background, never holding up the start).
      reportInstall('open').catch(() => undefined);
      api
        .get<BusinessSettings>('public/settings', undefined, { auth: false, timeoutMs: 8000 })
        .then((res) => followRemoteServer(res.data, get().apiUrl, (url) => get().setServer(url, 'remote')))
        .catch(() => undefined);
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
      runtime.set({ token: stored.token, userId: stored.user.id, userType: stored.user.type, branchId: stored.branchId });
      applyLocale(stored.user, prefs.locale);
      // Ready straight away from the phone's copy: the network refresh happens in the background.
      set({ status: 'ready', user: stored.user, branchId: stored.branchId, apiUrl, settings });
      get().refresh().catch(() => undefined);
      emit('signedIn');
    },

    async setServer(url, source = 'manual') {
      const apiUrl = normaliseServer(url);
      await secure.saveServer(apiUrl || null);
      await setMeta('serverSource', apiUrl ? source : null);
      await setMeta('serverRejected', null);
      runtime.set({ apiUrl: apiUrl || DEFAULT_API_URL });
      set({ apiUrl: apiUrl || DEFAULT_API_URL });
    },

    async login(login, password) {
      const deviceName = [Device.manufacturer, Device.modelName].filter(Boolean).join(' ') || 'Phone';
      const net = await NetInfo.fetch().catch(() => null);
      const background = get().offline; // finishing an offline sign-in, not the sign-in screen
      if (!background && net && net.isConnected === false) {
        await signInOffline(login, password);
        return 'ok';
      }
      let res: Awaited<ReturnType<typeof api.post<LoginResult & { two_factor_required?: boolean; challenge?: string }>>>;
      try {
        res = await api.post<LoginResult & { two_factor_required?: boolean; challenge?: string }>(
          'auth/login',
          { login, password, client: 'mobile', device_name: `PatoPro app · ${deviceName}` },
          { auth: false, timeoutMs: 15000 },
        );
      } catch (e) {
        if (e instanceof ApiError && !e.offline && !background && [401, 403, 422, 423].includes(e.status)) {
          reportInstall('login_failed', login).catch(() => undefined);
        }
        if (!(e instanceof ApiError) || !e.offline || background) throw e;
        // The phone has a network but the server did not answer: the offline copy still works.
        const saved = await secure.checkOfflineLogin(login, password);
        if (saved.result === 'ok' || saved.result === 'wrong') {
          await signInOffline(login, password);
          return 'ok';
        }
        const noInternet = net?.isInternetReachable === false;
        throw new ApiError(
          noInternet
            ? t('No internet. The first sign-in on this phone needs internet; after that you can sign in without it.')
            : e.code === 'TIMEOUT'
              ? t('The PatoPro server took too long to answer. Check the internet (data bundle / Wi-Fi) and try again.')
              : t('Cannot reach the PatoPro server. Check that the phone has internet (data bundle / Wi-Fi) and try again.'),
          0,
          noInternet ? 'OFFLINE_FIRST' : 'SERVER_UNREACHABLE',
          null,
          true,
        );
      }
      if (res.data.two_factor_required && res.data.challenge) {
        pending = { login, password };
        set({ status: 'twoFactor', challenge: res.data.challenge, offline: false });
        return 'two_factor';
      }
      pending = { login, password };
      await finish(res.data);
      return 'ok';
    },

    async register(data) {
      const res = await api.post<{ token: string; user: User }>('shop/register', data, { auth: false });
      await finish({ token: res.data.token, expires_at: new Date(Date.now() + 30 * 86400_000).toISOString(), user: res.data.user });
    },

    async verifyTwoFactor(code) {
      const challenge = get().challenge;
      if (!challenge) throw new ApiError('Sign in again.', 422);
      const res = await api.post<LoginResult>('auth/two-factor/verify', { challenge, code, device_name: 'PatoPro app' }, { auth: false });
      await finish(res.data);
    },

    /** Latest user, permissions and business settings (silently skipped offline). */
    async refresh() {
      if (get().user?.type === 'customer') {
        try {
          const [me, pub] = await Promise.all([api.get<User>('shop/account'), api.get<BusinessSettings>('public/settings', undefined, { auth: false })]);
          setCurrency(pub.data.currency);
          await setMeta('settings', pub.data);
          set({ user: { ...get().user, ...me.data } as User, settings: pub.data });
          await persist();
        } catch (e) {
          if (!(e instanceof ApiError) || !e.offline) throw e;
        }
        return;
      }
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
      const { installationId, userType } = runtime.get();
      // Best effort: the phone forgets the login even when offline.
      const devices = userType === 'customer' ? 'shop/account/devices' : 'admin/mobile/devices';
      if (installationId) await api.delete(`${devices}/${installationId}`, { timeoutMs: 5000 }).catch(() => undefined);
      await api.post('auth/logout', {}, { timeoutMs: 5000 }).catch(() => undefined);
      pending = null;
      stopWatching?.();
      stopWatching = null;
      await emit('signedOut');
      await secure.saveSession(null);
      runtime.set({ token: null });
      set({ status: 'signedOut', user: null, challenge: null, expired: false, offline: false });
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
