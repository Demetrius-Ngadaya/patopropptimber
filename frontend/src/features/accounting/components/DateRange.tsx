"use client";

import { monthStart, today } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Button, Field, Input } from "@/components/ui";

export type Range = { date_from: string; date_to: string };

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

export function presetRanges(): Record<string, Range> {
  const now = new Date();
  const y = now.getFullYear();
  const m = now.getMonth();
  const q = Math.floor(m / 3);
  return {
    "This month": { date_from: monthStart(), date_to: today() },
    "Last month": { date_from: iso(new Date(y, m - 1, 1)), date_to: iso(new Date(y, m, 0)) },
    "This quarter": { date_from: iso(new Date(y, q * 3, 1)), date_to: today() },
    "This year": { date_from: `${y}-01-01`, date_to: today() },
    "Last year": { date_from: `${y - 1}-01-01`, date_to: `${y - 1}-12-31` },
  };
}

/** From / to dates with one-click periods. `single` shows one "as of" date. */
export function DateRange({ value, onChange, single = false }: { value: Range; onChange: (r: Range) => void; single?: boolean }) {
  const t = useT();
  return (
    <div className="flex flex-wrap items-end gap-3">
      {!single && <Field label={t("From")}>{(p) => <Input {...p} type="date" value={value.date_from} max={value.date_to} onChange={(e) => onChange({ ...value, date_from: e.target.value })} />}</Field>}
      <Field label={single ? t("As of") : t("To")}>{(p) => <Input {...p} type="date" value={value.date_to} onChange={(e) => onChange({ ...value, date_to: e.target.value })} />}</Field>
      {!single && (
        <div className="flex flex-wrap gap-1.5 pb-0.5">
          {Object.entries(presetRanges()).map(([label, r]) => (
            <Button key={label} size="sm" variant={value.date_from === r.date_from && value.date_to === r.date_to ? "primary" : "secondary"} onClick={() => onChange(r)}>{t(label)}</Button>
          ))}
        </div>
      )}
    </div>
  );
}
