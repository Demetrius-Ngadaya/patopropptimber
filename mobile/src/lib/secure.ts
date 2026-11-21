import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import * as Crypto from 'expo-crypto';

/** Secrets and per-installation ids, kept in the phone's keychain / keystore. */
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK };

const KEYS = {
  token: 'pp.token',
  session: 'pp.session',
  installation: 'pp.installation',
  server: 'pp.server',
  pin: 'pp.pin',
  offline: 'pp.offline-login',
} as const;

// The browser preview has no keychain: there (only) values live in localStorage.
const web = Platform.OS === 'web';

async function get(key: string): Promise<string | null> {
  if (web) return globalThis.localStorage?.getItem(key) ?? null;
  try {
    return await SecureStore.getItemAsync(key, OPTIONS);
  } catch {
    return null;
  }
}

async function set(key: string, value: string | null): Promise<void> {
  if (web) {
    if (value === null) globalThis.localStorage?.removeItem(key);
    else globalThis.localStorage?.setItem(key, value);
    return;
  }
  if (value === null) {
    await SecureStore.deleteItemAsync(key, OPTIONS).catch(() => undefined);
  } else {
    await SecureStore.setItemAsync(key, value, OPTIONS);
  }
}

export type StoredSession = {
  token: string;
  expiresAt: string | null;
  user: import('./types').User;
  branchId: number | null;
};

/**
 * What lets the last person sign in again with no internet: a salted hash of
 * their password (never the password), the names they may type, and who they are.
 */
export type OfflineLogin = {
  identifiers: string[];
  salt: string;
  hash: string;
  user: import('./types').User;
  branchId: number | null;
  /** Last time the server checked this password: offline sign-in works for 30 days after it. */
  verifiedAt: string;
  /** The business server it belongs to (a saved sign-in never opens another business). */
  server?: string;
};

export const OFFLINE_LOGIN_DAYS = 30;

const passwordHash = (salt: string, password: string) => Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, `${salt}:patopro-offline:${password}`);
const clean = (s: string | null | undefined) => (s ?? '').trim().toLowerCase();

export const secure = {
  async session(): Promise<StoredSession | null> {
    const raw = await get(KEYS.session);
    if (!raw) return null;
    try {
      return JSON.parse(raw) as StoredSession;
    } catch {
      return null;
    }
  },
  saveSession: (s: StoredSession | null) => set(KEYS.session, s ? JSON.stringify(s) : null),

  /** One id per installation: identifies this phone for push and sync. */
  async installationId(): Promise<string> {
    const existing = await get(KEYS.installation);
    if (existing) return existing;
    const id = Crypto.randomUUID();
    await set(KEYS.installation, id);
    return id;
  },

  /** After a sign-in the server accepted: remember it for signing in offline later. */
  async rememberOfflineLogin(typed: string, password: string, user: import('./types').User, branchId: number | null, server?: string): Promise<void> {
    const salt = Crypto.randomUUID();
    const identifiers = [...new Set([typed, user.username, user.email, user.phone].map(clean).filter(Boolean))];
    const record: OfflineLogin = { identifiers, salt, hash: await passwordHash(salt, password), user, branchId, verifiedAt: new Date().toISOString(), server };
    await set(KEYS.offline, JSON.stringify(record));
  },
  async offlineLogin(): Promise<OfflineLogin | null> {
    const raw = await get(KEYS.offline);
    if (!raw) return null;
    try {
      return JSON.parse(raw) as OfflineLogin;
    } catch {
      return null;
    }
  },
  /**
   * 'ok' with the saved person, 'unknown' when this phone has no saved sign-in for
   * that name (or it is too old), 'wrong' when the password does not match.
   */
  async checkOfflineLogin(typed: string, password: string, server?: string): Promise<{ result: 'ok'; login: OfflineLogin } | { result: 'unknown' | 'wrong' | 'stale' }> {
    const login = await secure.offlineLogin();
    if (!login || !login.identifiers.includes(clean(typed))) return { result: 'unknown' };
    if (server && login.server && login.server !== server) return { result: 'unknown' };
    if (Date.now() - new Date(login.verifiedAt).getTime() > OFFLINE_LOGIN_DAYS * 86400_000) return { result: 'stale' };
    return (await passwordHash(login.salt, password)) === login.hash ? { result: 'ok', login } : { result: 'wrong' };
  },
  forgetOfflineLogin: () => set(KEYS.offline, null),

  server: () => get(KEYS.server),
  saveServer: (url: string | null) => set(KEYS.server, url),

  /** App PIN, stored as a salted SHA-256 hash. */
  async setPin(pin: string | null): Promise<void> {
    if (!pin) return set(KEYS.pin, null);
    const salt = Crypto.randomUUID();
    const hash = await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, `${salt}:${pin}`);
    await set(KEYS.pin, `${salt}:${hash}`);
  },
  async hasPin(): Promise<boolean> {
    return !!(await get(KEYS.pin));
  },
  async checkPin(pin: string): Promise<boolean> {
    const stored = await get(KEYS.pin);
    if (!stored) return false;
    const [salt, hash] = stored.split(':');
    const attempt = await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, `${salt}:${pin}`);
    return attempt === hash;
  },
};
