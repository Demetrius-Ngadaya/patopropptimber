"use client";

import { Monitor, Moon, Sun } from "lucide-react";
import { usePersistPreference } from "@/components/layout/PreferenceSwitches";
import { cn } from "@/lib/format";
import { LOCALE_NAMES, LOCALES, useLocale, useT } from "@/lib/i18n";
import { useTheme, type ThemePreference } from "@/lib/theme/ThemeProvider";
import { Panel } from "@/components/ui";

function Choice({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button type="button" aria-pressed={active} onClick={onClick}
      className={cn("flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2.5 text-sm font-medium ring-1 transition", active ? "bg-brand-50 text-brand-800 ring-brand-500" : "text-ink-600 ring-ink-200 hover:bg-ink-50")}>
      {children}
    </button>
  );
}

/** Language and appearance, saved to the account so they follow the user to every device. */
export function PreferencesPanel() {
  const t = useT();
  const { locale, setLocale } = useLocale();
  const { theme, setTheme } = useTheme();
  const persist = usePersistPreference();
  const themes: Array<[ThemePreference, string, typeof Sun]> = [["light", t("Light"), Sun], ["dark", t("Dark"), Moon], ["system", t("Same as device"), Monitor]];
  return (
    <Panel title={t("Language & appearance")}>
      <p className="mb-2 text-sm font-medium text-ink-700">{t("Language")}</p>
      <div className="mb-5 flex gap-2">
        {LOCALES.map((l) => <Choice key={l} active={locale === l} onClick={() => { setLocale(l); persist({ locale: l }); }}>{LOCALE_NAMES[l].native}</Choice>)}
      </div>
      <p className="mb-2 text-sm font-medium text-ink-700">{t("Theme")}</p>
      <div className="flex gap-2">
        {themes.map(([value, label, Icon]) => <Choice key={value} active={theme === value} onClick={() => { setTheme(value); persist({ theme: value }); }}><Icon className="size-4" />{label}</Choice>)}
      </div>
    </Panel>
  );
}
