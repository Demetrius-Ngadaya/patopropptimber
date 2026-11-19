import Constants from 'expo-constants';
import * as Application from 'expo-application';
import * as Device from 'expo-device';
import { Platform } from 'react-native';
import { runtime } from './runtime';
import { getMeta, setMeta } from '../db/meta';

/** "https://api.x.co.tz/api/v1/" → "https://api.x.co.tz" */
export function normaliseServer(url: string): string {
  let u = url.trim().replace(/\/+$/, '');
  if (u && !/^https?:\/\//i.test(u)) u = `https://${u}`;
  return u.replace(/\/api(\/v1)?$/i, '');
}

/** True when a PatoPro server answers at this address (checked before switching). */
export async function checkServer(url: string, timeoutMs = 8000): Promise<boolean> {
  const base = normaliseServer(url);
  if (!base) return false;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${base}/api/v1/health`, { signal: controller.signal, headers: { Accept: 'application/json' } });
    if (!res.ok) return false;
    const body = (await res.json().catch(() => null)) as { status?: string } | null;
    return body?.status === 'ok';
  } catch {
    return false;
  } finally {
    clearTimeout(timer);
  }
}

/** What this phone is, for the "Mobile app users" report. */
export function deviceInfo() {
  return {
    platform: Platform.OS === 'ios' ? 'ios' : 'android',
    device_model: [Device.manufacturer, Device.modelName].filter(Boolean).join(' ').slice(0, 120) || null,
    os_version: Device.osVersion ? String(Device.osVersion).slice(0, 40) : null,
    app_version: Constants.expoConfig?.version ?? Application.nativeApplicationVersion ?? null,
    build_number: Number(Application.nativeBuildVersion) || null,
  };
}

/**
 * Tells the server that the app was opened, or that a sign-in failed (what was
 * typed as the username, never the password). Best effort, never blocks the app.
 */
export async function reportInstall(event: 'open' | 'login_failed', login?: string): Promise<void> {
  const { apiUrl, installationId, locale } = runtime.get();
  if (!apiUrl || !installationId) return;
  if (event === 'open') {
    // Once per app start is enough; skip if the last report was under an hour ago.
    const last = await getMeta<number>('installPingAt', 0);
    if (Date.now() - last < 3600_000) return;
    await setMeta('installPingAt', Date.now());
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 8000);
  try {
    await fetch(`${apiUrl}/api/v1/public/mobile/installs`, {
      method: 'POST',
      signal: controller.signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ installation_id: installationId, event, locale, login: login?.slice(0, 80), ...deviceInfo() }),
    });
  } catch {
    // Offline: the next start tries again.
    if (event === 'open') await setMeta('installPingAt', 0);
  } finally {
    clearTimeout(timer);
  }
}
