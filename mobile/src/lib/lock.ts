import { AppState } from 'react-native';
import * as LocalAuthentication from 'expo-local-authentication';
import { create } from 'zustand';
import { currentPrefs } from './prefs';
import { secure } from './secure';

/**
 * App lock: after the phone was away from the app for a while, ask for the
 * fingerprint / face or the app PIN. The login itself stays (the app can keep
 * selling offline for days), so the lock is what protects an unattended phone.
 */
type LockState = { locked: boolean; lock: () => void; unlock: () => void };

export const useLock = create<LockState>((set) => ({
  locked: false,
  lock: () => set({ locked: true }),
  unlock: () => set({ locked: false }),
}));

export async function lockEnabled(): Promise<boolean> {
  const prefs = await currentPrefs();
  return prefs.security.biometric || (prefs.security.pin && (await secure.hasPin()));
}

export async function biometricAvailable(): Promise<boolean> {
  try {
    return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync());
  } catch {
    return false;
  }
}

export async function unlockWithBiometrics(prompt: string, fallback: string): Promise<boolean> {
  const result = await LocalAuthentication.authenticateAsync({ promptMessage: prompt, cancelLabel: fallback, disableDeviceFallback: false });
  return result.success;
}

let leftAt: number | null = null;

/** Locks on start (when enabled) and after the chosen minutes in the background. */
export function watchAppLock(): () => void {
  lockEnabled().then((on) => on && useLock.getState().lock());
  const sub = AppState.addEventListener('change', async (state) => {
    if (state === 'background') leftAt = Date.now();
    if (state === 'active' && leftAt) {
      const prefs = await currentPrefs();
      const away = Date.now() - leftAt;
      leftAt = null;
      if ((await lockEnabled()) && away >= prefs.security.lockAfterMinutes * 60_000) useLock.getState().lock();
    }
  });
  return () => sub.remove();
}
