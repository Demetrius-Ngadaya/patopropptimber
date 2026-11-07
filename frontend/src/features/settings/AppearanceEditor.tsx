"use client";

import { Check, RotateCcw, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { COLOR_PRESETS, DEFAULT_COLORS, contrast, isHex, type BrandColors } from "@/lib/theme/palette";

type Key = "primary" | "accent" | "sidebar";

const FIELDS: { key: Key; label: string; help: string }[] = [
  { key: "primary", label: "Main colour", help: "Buttons, links, selected items and highlights." },
  { key: "accent", label: "Accent colour", help: "Totals, prices and things that need attention." },
  { key: "sidebar", label: "Sidebar colour", help: "The menu on the left and dark headers." },
];

/** Presets plus one colour picker per token, with a live preview and a readability check. */
export function AppearanceEditor({ value, onChange, disabled, errors }: {
  value: BrandColors;
  onChange: (key: Key, value: string | null) => void;
  disabled?: boolean;
  errors?: Partial<Record<Key, string>>;
}) {
  const t = useT();
  const pick = (k: Key) => (isHex(value[k]) ? value[k]!.toLowerCase() : DEFAULT_COLORS[k]);
  const colors = { primary: pick("primary"), accent: pick("accent"), sidebar: pick("sidebar") };
  const custom = FIELDS.some((f) => isHex(value[f.key]));
  const applyPreset = (p: (typeof COLOR_PRESETS)[number]) => {
    const isDefault = p.primary === DEFAULT_COLORS.primary && p.accent === DEFAULT_COLORS.accent && p.sidebar === DEFAULT_COLORS.sidebar;
    FIELDS.forEach((f) => onChange(f.key, isDefault ? null : p[f.key]));
  };
  const lowContrast = contrast(colors.primary, "#ffffff") < 3;
  const lowSidebar = contrast(colors.sidebar, "#ffffff") < 4.5;

  return (
    <div className="space-y-6">
      <div>
        <p className="mb-2 text-sm font-medium text-ink-700">{t("Ready-made colours")}</p>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {COLOR_PRESETS.map((p) => {
            const active = FIELDS.every((f) => colors[f.key] === p[f.key]);
            return (
              <button key={p.name} type="button" disabled={disabled} onClick={() => applyPreset(p)}
                className={cn("flex items-center gap-2 rounded-md p-2 text-left text-[13px] ring-1 transition-colors disabled:cursor-not-allowed disabled:opacity-60", active ? "ring-2 ring-ink-800" : "ring-ink-200 hover:bg-ink-50")}>
                <span className="flex shrink-0 overflow-hidden rounded ring-1 ring-black/10">
                  <span className="size-5" style={{ background: p.sidebar }} />
                  <span className="size-5" style={{ background: p.primary }} />
                  <span className="size-5" style={{ background: p.accent }} />
                </span>
                <span className="min-w-0 flex-1 truncate text-ink-800">{t(p.name)}</span>
                {active && <Check className="size-3.5 shrink-0 text-ink-800" />}
              </button>
            );
          })}
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        {FIELDS.map((f) => (
          <div key={f.key}>
            <label htmlFor={`color-${f.key}`} className="mb-1 block text-sm font-medium text-ink-700">{t(f.label)}</label>
            <div className="flex items-center gap-2">
              <input id={`color-${f.key}`} type="color" disabled={disabled} value={colors[f.key]} onChange={(e) => onChange(f.key, e.target.value)}
                className="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-ink-200 bg-surface p-0.5 disabled:cursor-not-allowed" />
              <input type="text" aria-label={t(f.label)} disabled={disabled} spellCheck={false} maxLength={7} placeholder={DEFAULT_COLORS[f.key]}
                value={isHex(value[f.key]) ? value[f.key]! : (value[f.key] ?? "")}
                onChange={(e) => { const v = e.target.value.trim(); onChange(f.key, v === "" ? null : v.startsWith("#") ? v : `#${v}`); }}
                className="h-9 w-full min-w-0 rounded-md border border-ink-200 bg-surface px-2.5 font-mono text-sm text-ink-900 uppercase outline-none focus:border-brand-500" />
            </div>
            <p className="mt-1 text-xs text-ink-500">{t(f.help)}</p>
            {errors?.[f.key] && <p className="mt-1 text-xs text-ember-600">{errors[f.key]}</p>}
          </div>
        ))}
      </div>

      {(lowContrast || lowSidebar) && (
        <p className="flex items-start gap-2 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-700">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" />
          {lowContrast ? t("The main colour is very light: white text on buttons will be hard to read. Choose a darker colour.") : t("The sidebar colour is light: the white menu text will be hard to read. Choose a darker colour.")}
        </p>
      )}

      <div>
        <p className="mb-2 text-sm font-medium text-ink-700">{t("Preview")}</p>
        <div className="keep-light flex overflow-hidden rounded-lg ring-1 ring-ink-200">
          <div className="w-32 shrink-0 space-y-1.5 p-3" style={{ background: colors.sidebar }}>
            <div className="h-2 w-16 rounded bg-white/70" />
            <div className="rounded px-2 py-1 text-[11px] font-medium text-white" style={{ background: colors.primary }}>{t("Dashboard")}</div>
            <div className="px-2 text-[11px] text-white/75">{t("Sales")}</div>
            <div className="px-2 text-[11px] text-white/75">{t("Products")}</div>
          </div>
          <div className="flex-1 space-y-3 bg-white p-4">
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-[#13213b]">{t("Total")}</span>
              <span className="text-lg font-bold" style={{ color: colors.accent }}>TSh 125,000</span>
            </div>
            <div className="flex gap-2">
              <span className="rounded-md px-3 py-1.5 text-xs font-medium text-white" style={{ background: colors.primary }}>{t("Save")}</span>
              <span className="rounded-md px-3 py-1.5 text-xs font-medium ring-1 ring-[#dde2ea]" style={{ color: colors.primary }}>{t("Cancel")}</span>
            </div>
          </div>
        </div>
        <p className="mt-2 text-xs text-ink-500">{t("Dark mode uses matching darker shades automatically. Everyone sees the new colours after saving.")}</p>
      </div>

      {custom && !disabled && (
        <button type="button" onClick={() => FIELDS.forEach((f) => onChange(f.key, null))} className="inline-flex items-center gap-1.5 text-[13px] font-medium text-brand-700 hover:underline">
          <RotateCcw className="size-3.5" /> {t("Use the built-in colours")}
        </button>
      )}
    </div>
  );
}
