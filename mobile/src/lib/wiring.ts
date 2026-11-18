import { onSession } from './session';
import { onPrefsChanged } from './prefs';
import { runtime } from './runtime';
import { useLocale } from '../i18n';
import { registerDevice, updateBadge } from '../notifications';
import { ensureChannels } from '../notifications/channels';
import { onSyncPrefsChanged, requestSync, startSync, stopSync, switchBranch } from '../sync/manager';
import { registerBackgroundWork, unregisterBackgroundWork } from '../tasks';

/**
 * Connects the pieces without import cycles: signing in starts the sync and
 * registers the phone for notifications; signing out stops both; changing
 * notification settings tells the server.
 */
let wired = false;

export function wireApp() {
  if (wired) return;
  wired = true;

  onSession(async (event) => {
    if (event === 'signedIn') {
      const customer = runtime.get().userType === 'customer';
      if (!customer) await startSync(); // the shop works online; only staff keep an offline copy
      registerDevice(true).catch(() => undefined);
      registerBackgroundWork(customer).catch(() => undefined);
    }
    if (event === 'refreshed') updateBadge().catch(() => undefined);
    if (event === 'branchChanged') await switchBranch();
    if (event === 'signedOut') {
      stopSync();
      await unregisterBackgroundWork();
      // The phone's copy (products, customers, unsent sales) stays, so the same person
      // can sign in again even without internet. If someone else signs in, it is wiped
      // first (session.ts → finish).
    }
  });

  onPrefsChanged(async (next, previous) => {
    if (next.locale !== previous.locale) {
      const locale = next.locale ?? runtime.get().locale;
      useLocale.getState().setLocale(locale);
      runtime.set({ locale });
      await ensureChannels(); // channel names in the new language
    }
    if (JSON.stringify(next.notifications) !== JSON.stringify(previous.notifications) || next.locale !== previous.locale) {
      registerDevice(false).catch(() => undefined);
    }
    if (JSON.stringify(next.sync) !== JSON.stringify(previous.sync)) {
      await onSyncPrefsChanged();
      registerBackgroundWork().catch(() => undefined);
      if (next.sync.auto && !previous.sync.auto) requestSync('manual');
    }
  });
}
