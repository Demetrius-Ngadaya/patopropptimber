import * as Speech from 'expo-speech';
import { currentPrefs } from '../lib/prefs';
import { useLocale } from '../i18n';

/**
 * Reads notifications out loud. Uses the phone's text-to-speech engine, so it
 * works offline. Kiswahili needs a Kiswahili voice on the phone (Google
 * Text-to-speech has one); without it the app falls back to English.
 */
let voices: Speech.Voice[] | null = null;
const spoken = new Set<string>();

async function availableVoices(): Promise<Speech.Voice[]> {
  if (voices) return voices;
  try {
    voices = await Speech.getAvailableVoicesAsync();
  } catch {
    voices = [];
  }
  return voices;
}

export async function hasVoiceFor(language: 'sw' | 'en'): Promise<boolean> {
  const list = await availableVoices();
  // Some phones report no list at all but still speak: only say "missing" when there is a list.
  if (!list.length) return language === 'en';
  return list.some((v) => v.language?.toLowerCase().startsWith(language));
}

async function languageFor(textLocale: 'sw' | 'en'): Promise<string> {
  const prefs = await currentPrefs();
  const wanted = prefs.notifications.voiceLanguage === 'auto' ? textLocale : prefs.notifications.voiceLanguage;
  if (wanted === 'sw' && (await hasVoiceFor('sw'))) return 'sw-TZ';
  return 'en-US';
}

export function voiceText(title: string, body?: string | null): string {
  const text = [title, body].filter(Boolean).join('. ');
  // Long bodies (reports) are cut: the details are on the screen.
  return text.length > 220 ? `${text.slice(0, 217).replace(/\s+\S*$/, '')}…` : text;
}

/**
 * Speak once per notification, even if it is seen by several handlers
 * (foreground listener and background task run in the same app).
 */
export async function speakOnce(key: string, title: string, body?: string | null): Promise<void> {
  if (spoken.has(key)) return;
  spoken.add(key);
  if (spoken.size > 300) spoken.delete(spoken.values().next().value as string);
  await speak(voiceText(title, body));
}

export async function speak(text: string): Promise<void> {
  const prefs = await currentPrefs();
  const language = await languageFor(useLocale.getState().locale);
  if (await Speech.isSpeakingAsync().catch(() => false)) await Speech.stop().catch(() => undefined);
  Speech.speak(text, { language, rate: prefs.notifications.voiceRate, pitch: 1, volume: 1 });
}
