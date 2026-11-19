import { ExpoSpeechRecognitionModule } from 'expo-speech-recognition';
import * as Speech from 'expo-speech';
import { hasVoiceFor } from '../notifications/voice';
import { currentPrefs } from '../lib/prefs';

/**
 * The phone's microphone and voice. Listening uses the phone's speech service
 * (Google on Android: Kiswahili "sw-TZ" and English); speaking uses its
 * text-to-speech, which works offline.
 */
export type Heard = { text: string; alternatives: string[] };

export const recognitionLanguage = (lang: 'sw' | 'en') => (lang === 'sw' ? 'sw-TZ' : 'en-US');

export async function microphoneAllowed(): Promise<boolean> {
  try {
    const res = await ExpoSpeechRecognitionModule.requestPermissionsAsync();
    return res.granted;
  } catch {
    return false;
  }
}

export function recognitionAvailable(): boolean {
  try {
    return ExpoSpeechRecognitionModule.isRecognitionAvailable();
  } catch {
    return false;
  }
}

/**
 * Listen once (until the person stops talking). `onPartial` shows words as they
 * are heard. Resolves with the final words, or rejects with a reason code.
 */
export function listenOnce(lang: 'sw' | 'en', hints: string[], onPartial: (text: string) => void): { done: Promise<Heard>; stop: () => void; cancel: () => void } {
  let finish: (h: Heard) => void = () => undefined;
  let fail: (e: Error) => void = () => undefined;
  const done = new Promise<Heard>((resolve, reject) => {
    finish = resolve;
    fail = reject;
  });
  let last: Heard = { text: '', alternatives: [] };
  let settled = false;
  const subs = [
    ExpoSpeechRecognitionModule.addListener('result', (e) => {
      const list = e.results.map((r) => r.transcript).filter(Boolean);
      if (!list.length) return;
      last = { text: list[0], alternatives: list.slice(1) };
      if (e.isFinal) {
        settled = true;
        finish(last);
      } else onPartial(list[0]);
    }),
    ExpoSpeechRecognitionModule.addListener('error', (e) => {
      if (settled) return;
      settled = true;
      if (e.error === 'aborted') fail(new Error('aborted'));
      else if (e.error === 'no-speech' || e.error === 'speech-timeout') fail(new Error('no-speech'));
      else fail(new Error(e.error));
    }),
    ExpoSpeechRecognitionModule.addListener('end', () => {
      if (settled) return;
      settled = true;
      if (last.text) finish(last);
      else fail(new Error('no-speech'));
    }),
  ];
  const cleanup = () => subs.forEach((s) => s.remove());
  done.then(cleanup, cleanup);

  try {
    ExpoSpeechRecognitionModule.start({
      lang: recognitionLanguage(lang),
      interimResults: true,
      maxAlternatives: 3,
      continuous: false,
      addsPunctuation: false,
      contextualStrings: hints.slice(0, 100),
      androidIntentOptions: { EXTRA_SPEECH_INPUT_COMPLETE_SILENCE_LENGTH_MILLIS: 1500, EXTRA_LANGUAGE_MODEL: 'free_form' },
    });
  } catch (e) {
    settled = true;
    fail(e instanceof Error ? e : new Error(String(e)));
  }
  return {
    done,
    stop: () => ExpoSpeechRecognitionModule.stop(),
    cancel: () => ExpoSpeechRecognitionModule.abort(),
  };
}

/** Say a status out loud in the voice-mode language (stops anything still being said). */
export async function say(text: string, lang: 'sw' | 'en'): Promise<void> {
  const prefs = await currentPrefs();
  if (!text) return;
  const language = lang === 'sw' && (await hasVoiceFor('sw')) ? 'sw-TZ' : lang === 'sw' ? 'sw' : 'en-US';
  await Speech.stop().catch(() => undefined);
  await new Promise<void>((resolve) => {
    Speech.speak(text, { language, rate: prefs.notifications.voiceRate, pitch: 1, onDone: resolve, onStopped: resolve, onError: () => resolve() });
    setTimeout(resolve, Math.min(15000, 1200 + text.length * 90)); // never wait forever
  });
}

export const stopSpeaking = () => Speech.stop().catch(() => undefined);
