import * as TaskManager from 'expo-task-manager';
import * as BackgroundTask from 'expo-background-task';
import * as Notifications from 'expo-notifications';
import { runtime } from './lib/runtime';
import { secure } from './lib/secure';
import { currentPrefs } from './lib/prefs';
import { DEFAULT_API_URL } from './lib/session';
import { deviceLocale, useLocale } from './i18n';
import { announce, announceFromSync, configureNotifications, updateBadge } from './notifications';
import { extractData } from './notifications/links';
import { requestSync, setPulledHook } from './sync/manager';

/**
 * Work that runs without the app on screen. Defined at module scope (loaded
 * from index.ts) because Android / iOS may start the JS just for a task:
 *  - SYNC_TASK: two-way sync every ~15 minutes (the OS decides the exact time);
 *  - NOTIFICATION_TASK: a push arrived; shows and speaks it (voice on Android
 *    arrives as a data-only message so this works even with the app closed),
 *    then fetches the new order / notification.
 */
export const SYNC_TASK = 'patopro-background-sync';
export const NOTIFICATION_TASK = 'patopro-notification';

configureNotifications();
setPulledHook(async (entity, rows, firstPage) => {
  if (entity === 'notifications') await announceFromSync(rows, firstPage);
});

/** In a background start, the session is not loaded yet: read it from the keychain. */
async function ensureRuntime(): Promise<boolean> {
  if (runtime.get().token) return true;
  const [stored, server, installationId, prefs] = await Promise.all([secure.session(), secure.server(), secure.installationId(), currentPrefs()]);
  if (!stored || (stored.expiresAt && new Date(stored.expiresAt).getTime() < Date.now())) return false;
  const locale = prefs.locale ?? stored.user.locale ?? deviceLocale();
  useLocale.getState().setLocale(locale);
  runtime.set({ apiUrl: server || DEFAULT_API_URL, token: stored.token, userId: stored.user.id, branchId: stored.branchId, installationId, locale });
  return true;
}

TaskManager.defineTask(SYNC_TASK, async () => {
  try {
    if (!(await ensureRuntime())) return BackgroundTask.BackgroundTaskResult.Success;
    const prefs = await currentPrefs();
    if (!prefs.sync.background) return BackgroundTask.BackgroundTaskResult.Success;
    const report = await requestSync('background');
    await updateBadge();
    return report && !report.ok && !report.offline ? BackgroundTask.BackgroundTaskResult.Failed : BackgroundTask.BackgroundTaskResult.Success;
  } catch {
    return BackgroundTask.BackgroundTaskResult.Failed;
  }
});

TaskManager.defineTask<Notifications.NotificationTaskPayload>(NOTIFICATION_TASK, async ({ data }) => {
  try {
    // A tap on a button: handled by the response listener when the app opens.
    if (!data || 'actionIdentifier' in data) return Notifications.BackgroundNotificationTaskResult.NoData;
    const payload = extractData(data.data ?? data);
    if (!(await ensureRuntime())) return Notifications.BackgroundNotificationTaskResult.NoData;
    if (payload.display) await announce(payload, 'push');
    // Bring the order (or whatever it is about) onto the phone for when it is opened.
    await requestSync('push', { only: payload.category === 'orders' ? ['orders', 'notifications'] : ['notifications'] });
    return Notifications.BackgroundNotificationTaskResult.NewData;
  } catch {
    return Notifications.BackgroundNotificationTaskResult.Failed;
  }
});

export async function registerBackgroundWork(): Promise<void> {
  try {
    await Notifications.registerTaskAsync(NOTIFICATION_TASK);
  } catch {
    // Not available in Expo Go: fine in a development / store build.
  }
  try {
    const prefs = await currentPrefs();
    const status = await BackgroundTask.getStatusAsync();
    if (status === BackgroundTask.BackgroundTaskStatus.Available && prefs.sync.background) {
      await BackgroundTask.registerTaskAsync(SYNC_TASK, { minimumInterval: 15 });
    } else if (await TaskManager.isTaskRegisteredAsync(SYNC_TASK)) {
      await BackgroundTask.unregisterTaskAsync(SYNC_TASK);
    }
  } catch {
    // The OS does not allow background work for this app.
  }
}

export async function unregisterBackgroundWork(): Promise<void> {
  await BackgroundTask.unregisterTaskAsync(SYNC_TASK).catch(() => undefined);
  await Notifications.unregisterTaskAsync(NOTIFICATION_TASK).catch(() => undefined);
}
