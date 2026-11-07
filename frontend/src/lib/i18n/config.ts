export const LOCALES = ["en", "sw"] as const;
export type Locale = (typeof LOCALES)[number];

export const DEFAULT_LOCALE: Locale = "en";
export const LOCALE_COOKIE = "mz_locale";

export const LOCALE_NAMES: Record<Locale, { native: string; english: string }> = {
  en: { native: "English", english: "English" },
  sw: { native: "Kiswahili", english: "Swahili" },
};

/** BCP 47 tags for Intl number and date formatting. */
export const INTL_LOCALE: Record<Locale, string> = { en: "en-GB", sw: "sw-TZ" };

export function isLocale(value: unknown): value is Locale {
  return typeof value === "string" && (LOCALES as readonly string[]).includes(value);
}
