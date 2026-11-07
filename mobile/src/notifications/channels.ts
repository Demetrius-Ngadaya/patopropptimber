import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { AndroidImportance, AndroidNotificationVisibility } from 'expo-notifications';
import { t } from '../i18n';

/**
 * Android fixes a channel's sound and vibration when it is created, so there
 * is one channel per mix and the server picks the channel that matches this
 * phone's settings for each kind of notification
 * (backend/app/Services/Mobile/PushNotifications.php::channelId).
 */
export const CHANNELS = {
  alert_sound_vibrate: { sound: true, vibrate: true },
  alert_sound: { sound: true, vibrate: false },
  alert_vibrate: { sound: false, vibrate: true },
  alert_silent: { sound: false, vibrate: false },
} as const;

export type ChannelId = keyof typeof CHANNELS;

/** Long-short-long: easy to feel in a pocket, distinct from a phone call. */
export const VIBRATION_PATTERN = [0, 450, 180, 450, 180, 700];

export function channelFor(sound: boolean, vibrate: boolean): ChannelId {
  if (sound && vibrate) return 'alert_sound_vibrate';
  if (sound) return 'alert_sound';
  if (vibrate) return 'alert_vibrate';
  return 'alert_silent';
}

export async function ensureChannels(): Promise<void> {
  if (Platform.OS !== 'android') return;
  const names: Record<ChannelId, string> = {
    alert_sound_vibrate: t('Alerts: sound and vibration'),
    alert_sound: t('Alerts: sound only'),
    alert_vibrate: t('Alerts: vibration only'),
    alert_silent: t('Alerts: silent'),
  };
  for (const [id, mix] of Object.entries(CHANNELS) as [ChannelId, (typeof CHANNELS)[ChannelId]][]) {
    await Notifications.setNotificationChannelAsync(id, {
      name: names[id],
      description: t('PatoPro notifications. Choose voice, sound and vibration per kind of notification in the app settings.'),
      importance: mix.sound || mix.vibrate ? AndroidImportance.MAX : AndroidImportance.DEFAULT,
      sound: mix.sound ? 'default' : null,
      enableVibrate: mix.vibrate,
      vibrationPattern: mix.vibrate ? VIBRATION_PATTERN : null,
      lightColor: '#16A34A',
      enableLights: true,
      showBadge: true,
      lockscreenVisibility: AndroidNotificationVisibility.PRIVATE,
    });
  }
}

/** Buttons on order notifications. */
export async function ensureActions(): Promise<void> {
  await Notifications.setNotificationCategoryAsync('orders', [
    { identifier: 'confirm_order', buttonTitle: t('Confirm order'), options: { opensAppToForeground: true } },
    { identifier: 'open', buttonTitle: t('Open'), options: { opensAppToForeground: true } },
  ]).catch(() => undefined);
}
