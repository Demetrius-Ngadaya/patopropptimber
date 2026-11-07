import { create } from 'zustand';
import { getLocales } from 'expo-localization';
import { SW } from './sw';

/**
 * English is written in the code; Kiswahili comes from sw.ts, keyed by the
 * English text (the same approach as the web app). `npm run i18n:check`
 * lists any text that has no Kiswahili yet.
 */
export type Locale = 'en' | 'sw';
export type Params = Record<string, string | number | null | undefined>;

export function deviceLocale(): Locale {
  try {
    return getLocales()[0]?.languageCode === 'sw' ? 'sw' : 'en';
  } catch {
    return 'en';
  }
}

export const useLocale = create<{ locale: Locale; setLocale: (l: Locale) => void }>((set) => ({
  locale: deviceLocale(),
  setLocale: (locale) => set({ locale }),
}));

export function translate(locale: Locale, text: string, params?: Params): string {
  let out = locale === 'sw' ? (SW[text] ?? text) : text;
  if (params) {
    for (const [k, v] of Object.entries(params)) out = out.split(`{${k}}`).join(v === null || v === undefined ? '' : String(v));
  }
  return out;
}

/** Translate outside React (notification texts, background tasks). */
export function t(text: string, params?: Params): string {
  return translate(useLocale.getState().locale, text, params);
}

export function useT() {
  const locale = useLocale((s) => s.locale);
  return (text: string, params?: Params) => translate(locale, text, params);
}
