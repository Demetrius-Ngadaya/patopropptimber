import { AppState, Platform } from 'react-native';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { api } from '../lib/api';
import { runtime } from '../lib/runtime';
import { currentPrefs, type Prefs } from '../lib/prefs';
import { getMeta, setMeta } from '../db/meta';
import { categoryForType } from '../db/categories';
import { markNotificationRead, setOrderStatus } from '../db/commands';
import { getOrder, unreadCount } from '../db/repo';
import { channelFor, ensureActions, ensureChannels } from './channels';
import { extractData, routeFor, type NotificationData } from './links';
import { speakOnce } from './voice';
import type { NotificationCategory } from '../lib/types';
import { t } from '../i18n';

/**
 * Notifications end to end:
 *  - push from the server (Expo) → notification bar, sound / vibration per
 *    Android channel, voice read out by the app;
 *  - a fallback for phones without push: new notifications found by the
 *    sync are shown as local notifications, with the same settings;
 *  - tapping one opens exactly the order / product / sale it is about.
 */

let configured = false;
let pendingRoute: string | null = null;

export function configureNotifications() {
  if (configured) return;
  configured = true;
  Notifications.setNotificationHandler({
    async handleNotification(notification) {
      const content = notification.request.content;
      const data = extractData(content.data);
      // Data-only pushes are shown by the background task as a local notification.
      const visible = !!content.title;
      return {
        shouldShowBanner: visible,
        shouldShowList: visible,
        shouldPlaySound: visible && data.sound !== false,
        shouldSetBadge: true,
      };
    },
  });
}

function isQuiet(prefs: Prefs, at = new Date()): boolean {
  const q = prefs.notifications.quietHours;
  if (!q.enabled) return false;
  const now = `${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;
  return q.from <= q.to ? now >= q.from && now < q.to : now >= q.from || now < q.to;
}

/** This phone's alert for a kind of notification right now (quiet hours applied). */
export async function alertFor(category: NotificationCategory) {
  const prefs = await currentPrefs();
  const mode = prefs.notifications.categories[category] ?? prefs.notifications.categories.general;
  if (isQuiet(prefs)) return { ...mode, voice: false, sound: false, vibrate: false };
  return mode;
}

async function remember(id: number): Promise<boolean> {
  if (!id) return true;
  const seen = await getMeta<number[]>('announced', []);
  if (seen.includes(id)) return false;
  await setMeta('announced', [...seen.slice(-299), id]);
  return true;
}

/**
 * Show a notification ourselves: for data-only pushes (voice on Android) and
 * for notifications found by the sync when push is not available.
 * Each notification id is announced once on this phone.
 */
export async function announce(data: NotificationData, source: 'push' | 'sync'): Promise<void> {
  const id = Number(data.notification_id ?? 0);
  if (!(await remember(id))) return;
  const category = (data.category as NotificationCategory) ?? categoryForType(data.type);
  const local = await alertFor(category);
  if (source === 'sync' && !local.enabled) return;

  // From the server the alert is already shaped for this phone; from the sync, use the local settings.
  const sound = source === 'push' ? data.sound !== false : local.sound;
  const vibrate = source === 'push' ? data.vibrate !== false : local.vibrate;
  const voice = source === 'push' ? !!data.speak : local.voice;
  const title = data.title ?? 'PatoPro';

  await Notifications.scheduleNotificationAsync({
    content: {
      title,
      body: data.body ?? undefined,
      data: { ...data, local: true, spoken: voice },
      sound: sound ? 'default' : false,
      categoryIdentifier: category === 'orders' ? 'orders' : undefined,
      color: '#0B3D91',
      badge: await unreadCount().catch(() => undefined),
    },
    trigger: Platform.OS === 'android' ? { channelId: data.channel_id ?? channelFor(sound, vibrate) } : null,
  });
  if (voice) await speakOnce(`n:${id || title}`, title, data.body);
}

/** New rows from the sync: announce the ones that arrived after this phone last looked. */
export async function announceFromSync(rows: Record<string, unknown>[], firstPage: boolean): Promise<void> {
  if (firstPage) {
    // First download after sign-in: history, not news.
    await setMeta('announced', rows.map((r) => Number(r.id)).slice(-300));
    return;
  }
  const fresh = rows.filter((r) => !r.read && Date.now() - new Date(String(r.created_at)).getTime() < 6 * 3600_000);
  for (const r of fresh.slice(-5)) {
    await announce({ notification_id: Number(r.id), type: String(r.type ?? ''), title: String(r.title), body: (r.body as string) ?? null, url: (r.url as string) ?? null }, 'sync');
  }
  await updateBadge();
}

export async function updateBadge(): Promise<void> {
  await Notifications.setBadgeCountAsync(await unreadCount()).catch(() => undefined);
}

/** Open the screen a notification is about (after sign-in / unlock if needed). */
export function openFromNotification(data: NotificationData) {
  const route = routeFor(data);
  const id = Number(data.notification_id ?? 0);
  if (id) markNotificationRead(id).catch(() => undefined);
  if (!runtime.get().token) {
    pendingRoute = route;
    return;
  }
  setTimeout(() => router.push(route as never), 50);
}

/** After sign-in or unlock: the notification that opened the app. */
export function takePendingRoute(): string | null {
  const r = pendingRoute;
  pendingRoute = null;
  return r;
}

async function handleResponse(response: Notifications.NotificationResponse) {
  const data = extractData(response.notification.request.content.data);
  if (response.actionIdentifier === 'confirm_order') {
    const orderId = Number(routeFor(data).match(/^\/orders\/(\d+)/)?.[1] ?? 0);
    const order = orderId ? await getOrder(orderId) : null;
    if (order && order.status === 'pending') await setOrderStatus(order, 'confirmed');
  }
  openFromNotification(data);
  await Notifications.dismissNotificationAsync(response.notification.request.identifier).catch(() => undefined);
}

/** Listeners while the app runs. Returns a cleanup. */
export function attachNotificationListeners(onPushData: (data: NotificationData) => void): () => void {
  const received = Notifications.addNotificationReceivedListener(async (n) => {
    const content = n.request.content;
    const data = extractData(content.data);
    if (content.title && !data.local) await remember(Number(data.notification_id ?? 0));
    if (content.title && data.speak && !data.spoken && AppState.currentState === 'active') {
      await speakOnce(`n:${data.notification_id ?? content.title}`, content.title, content.body);
    }
    if (!data.local) onPushData(data);
  });
  const responses = Notifications.addNotificationResponseReceivedListener((r) => {
    handleResponse(r).catch(() => undefined);
  });
  // The tap that launched the app from closed (phones only; the web preview has no notification bar).
  const last = Platform.OS === 'web' ? null : Notifications.getLastNotificationResponse();
  if (last) {
    Notifications.clearLastNotificationResponse();
    handleResponse(last).catch(() => undefined);
  }
  return () => {
    received.remove();
    responses.remove();
  };
}

// ------------------------------------------------------------ registration

export type PushStatus = { permission: 'granted' | 'denied' | 'undetermined'; token: string | null; reason: string | null };

let lastStatus: PushStatus = { permission: 'undetermined', token: null, reason: null };
export const pushStatus = () => lastStatus;

function projectId(): string | undefined {
  const extra = Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined;
  return extra?.eas?.projectId || Constants.easConfig?.projectId || undefined;
}

async function pushToken(ask: boolean): Promise<PushStatus> {
  await ensureChannels(); // Android 13+: the permission prompt needs a channel first.
  await ensureActions();
  let { status } = await Notifications.getPermissionsAsync();
  if (status !== 'granted' && ask) status = (await Notifications.requestPermissionsAsync()).status;
  const permission = status === 'granted' ? 'granted' : status === 'denied' ? 'denied' : 'undetermined';
  if (permission !== 'granted') return { permission, token: null, reason: 'permission' };
  if (!Device.isDevice) return { permission, token: null, reason: 'emulator' };
  const id = projectId();
  if (!id) return { permission, token: null, reason: 'no_project' };
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const token = await Notifications.getExpoPushTokenAsync({ projectId: id });
      return { permission, token: token.data, reason: null };
    } catch {
      await new Promise((r) => setTimeout(r, 1500 * (attempt + 1)));
    }
  }
  return { permission, token: null, reason: 'network' };
}

/** Tell the server about this phone: push token + how each kind of notification should alert. */
export async function registerDevice(ask = true): Promise<PushStatus> {
  lastStatus = await pushToken(ask);
  const prefs = await currentPrefs();
  const { installationId, locale } = runtime.get();
  if (!installationId || !runtime.get().token) return lastStatus;
  await api
    .post('admin/mobile/devices', {
      installation_id: installationId,
      platform: Platform.OS === 'ios' ? 'ios' : 'android',
      push_token: lastStatus.token,
      device_name: [Device.manufacturer, Device.modelName].filter(Boolean).join(' ') || null,
      app_version: Constants.expoConfig?.version ?? null,
      locale,
      preferences: {
        categories: prefs.notifications.categories,
        quiet_hours: {
          enabled: prefs.notifications.quietHours.enabled,
          from: prefs.notifications.quietHours.from,
          to: prefs.notifications.quietHours.to,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        },
      },
    })
    .catch(() => undefined); // offline: registered again on the next start
  return lastStatus;
}

/** "Send a test" in Settings: through the server and the real push service when possible. */
export async function sendTest(category: NotificationCategory): Promise<'push' | 'local'> {
  const { installationId } = runtime.get();
  if (lastStatus.token && installationId) {
    try {
      const res = await api.post<{ sent: boolean }>('admin/mobile/devices/test', { installation_id: installationId, category });
      if (res.data.sent) return 'push';
    } catch {
      // fall through to a local test
    }
  }
  const mode = await alertFor(category);
  await Notifications.scheduleNotificationAsync({
    content: {
      title: t('Test notification'),
      body: t('This is how PatoPro will alert you.'),
      data: { local: true, spoken: mode.voice, category },
      sound: mode.sound ? 'default' : false,
    },
    trigger: Platform.OS === 'android' ? { channelId: channelFor(mode.sound, mode.vibrate) } : null,
  });
  if (mode.voice) await speakOnce(`test:${Date.now()}`, t('Test notification'), t('This is how PatoPro will alert you.'));
  return 'local';
}
