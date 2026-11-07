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
