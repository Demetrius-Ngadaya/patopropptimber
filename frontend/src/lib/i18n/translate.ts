import type { Locale } from "./config";
import { sw } from "./dictionaries/sw";

/** Values for {placeholders} in a message. */
export type TParams = Record<string, string | number | null | undefined>;
export type TFunction = (text: string, params?: TParams) => string;

const dictionaries: Record<Locale, Record<string, string> | null> = { en: null, sw };

/**
 * English text is the key; other languages map it to a translation.
 * Missing translations fall back to English so nothing is ever blank.
 */
export function translate(locale: Locale, text: string, params?: TParams): string {
  const template = dictionaries[locale]?.[text] ?? text;
  if (!params) return template;
  return template.replace(/\{(\w+)\}/g, (match, name: string) => {
    const value = params[name];
    return value === undefined || value === null ? match : String(value);
  });
}

export function translator(locale: Locale): TFunction {
  return (text, params) => translate(locale, text, params);
}
