"use client";

import { createContext, useCallback, useContext, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { INTL_LOCALE, LOCALE_COOKIE, type Locale } from "./config";
import { translator, type TFunction } from "./translate";
import { setFormatLocale } from "@/lib/format";

type I18nContextValue = { locale: Locale; setLocale: (locale: Locale) => void; t: TFunction };

const I18nContext = createContext<I18nContextValue | null>(null);

/**
 * The locale comes from a cookie read on the server (app/layout.tsx), so the
 * first HTML is already in the right language and there is no flash.
 * Translations are taken from React context, never from a module global,
 * so concurrent server renders for different users cannot mix languages.
 */
export function I18nProvider({ initialLocale, children }: { initialLocale: Locale; children: React.ReactNode }) {
  const router = useRouter();
  const [locale, setLocaleState] = useState<Locale>(initialLocale);
  if (typeof window !== "undefined") setFormatLocale(INTL_LOCALE[locale]);

  const setLocale = useCallback(
    (next: Locale) => {
      document.cookie = `${LOCALE_COOKIE}=${next}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
      document.documentElement.lang = next;
      setFormatLocale(INTL_LOCALE[next]);
      setLocaleState(next);
      router.refresh(); // server-rendered parts pick up the new language
    },
    [router],
  );

  const value = useMemo(() => ({ locale, setLocale, t: translator(locale) }), [locale, setLocale]);
  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

function useI18n(): I18nContextValue {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useT must be used inside <I18nProvider>");
  return ctx;
}

/** const t = useT(); t("Save") / t("Hello {name}", { name }) */
export function useT(): TFunction {
  return useI18n().t;
}

export function useLocale(): Pick<I18nContextValue, "locale" | "setLocale"> {
  const { locale, setLocale } = useI18n();
  return { locale, setLocale };
}
