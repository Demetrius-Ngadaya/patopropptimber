import * as Haptics from 'expo-haptics';
import { usePrefs } from './prefs';

/** Taps and confirmations you can feel; off when "Vibrate on every tap" is off. */
const on = () => usePrefs.getState().pos.haptics;

export const haptic = {
  select: () => on() && Haptics.selectionAsync().catch(() => undefined),
  tap: () => on() && Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined),
  success: () => on() && Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined),
  warning: () => on() && Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => undefined),
  error: () => on() && Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error).catch(() => undefined),
};
