"use client";

import { Check, Languages, Monitor, Moon, Sun } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn } from "@/lib/format";
import { LOCALE_NAMES, LOCALES, useLocale, useT, type Locale } from "@/lib/i18n";
import { useTheme, type ThemePreference } from "@/lib/theme/ThemeProvider";

/** Saves a preference to the account when signed in, so it follows the user to other devices. */
export function usePersistPreference() {
  const { user } = useAuth();
  return (patch: { locale?: Locale; theme?: ThemePreference }) => {
    if (user) void api.put("auth/preferences", patch).catch(() => undefined);
  };
}

function useOutside(onClose: () => void) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) onClose();
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [onClose]);
  return ref;
}

export function LanguageSwitch({ compact = false, tone = "default" }: { compact?: boolean; tone?: "default" | "onDark" }) {
  const t = useT();
  const { locale, setLocale } = useLocale();
  const persist = usePersistPreference();
  const [open, setOpen] = useState(false);
  const ref = useOutside(() => setOpen(false));

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={t("Language")}
        className={cn(
          "inline-flex h-9 items-center gap-1.5 rounded-[var(--radius-control)] px-2.5 text-sm font-medium",
          tone === "onDark" ? "text-white/80 hover:bg-white/10 hover:text-white" : "text-ink-600 hover:bg-ink-100 hover:text-ink-900",
        )}
      >
        <Languages className="size-4" />
        {!compact && <span>{LOCALE_NAMES[locale].native}</span>}
        {compact && <span className="uppercase">{locale}</span>}
      </button>
      {open && (
        <div role="menu" className="absolute right-0 z-50 mt-1 w-44 overflow-hidden rounded-[var(--radius-control)] bg-surface py-1 shadow-lg ring-1 ring-ink-200">
          {LOCALES.map((l) => (
            <button
              key={l}
              role="menuitemradio"
              aria-checked={l === locale}
              type="button"
              onClick={() => {
                setOpen(false);
                if (l !== locale) {
                  setLocale(l);
                  persist({ locale: l });
                }
              }}
              className="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-ink-800 hover:bg-ink-50"
            >
              <span>
                {LOCALE_NAMES[l].native}
                {l !== "en" && <span className="ml-1 text-xs text-ink-500">({LOCALE_NAMES[l].english})</span>}
              </span>
              {l === locale && <Check className="size-4 text-brand-600" />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

const THEMES: { value: ThemePreference; label: string; icon: typeof Sun }[] = [
  { value: "light", label: "Light", icon: Sun },
  { value: "dark", label: "Dark", icon: Moon },
  { value: "system", label: "Same as device", icon: Monitor },
];

export function ThemeSwitch({ tone = "default" }: { tone?: "default" | "onDark" }) {
  const t = useT();
  const { theme, resolved, setTheme } = useTheme();
  const persist = usePersistPreference();
  const [open, setOpen] = useState(false);
  const ref = useOutside(() => setOpen(false));
  const Icon = resolved === "dark" ? Moon : Sun;

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={t("Theme")}
        className={cn(
          "grid size-9 place-items-center rounded-[var(--radius-control)]",
          tone === "onDark" ? "text-white/80 hover:bg-white/10 hover:text-white" : "text-ink-600 hover:bg-ink-100 hover:text-ink-900",
        )}
      >
        <Icon className="size-4" />
      </button>
      {open && (
        <div role="menu" className="absolute right-0 z-50 mt-1 w-48 overflow-hidden rounded-[var(--radius-control)] bg-surface py-1 shadow-lg ring-1 ring-ink-200">
          {THEMES.map(({ value, label, icon: ItemIcon }) => (
            <button
              key={value}
              role="menuitemradio"
              aria-checked={value === theme}
              type="button"
              onClick={() => {
                setOpen(false);
                setTheme(value);
                persist({ theme: value });
              }}
              className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ink-800 hover:bg-ink-50"
            >
              <ItemIcon className="size-4 text-ink-500" />
              <span className="flex-1">{t(label)}</span>
              {value === theme && <Check className="size-4 text-brand-600" />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
