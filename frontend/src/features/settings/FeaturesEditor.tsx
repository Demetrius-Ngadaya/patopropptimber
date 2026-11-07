"use client";

import { CalendarClock, Smartphone } from "lucide-react";
import { useT } from "@/lib/i18n";
import { Switch } from "@/components/ui";

const FEATURES = [
  { key: "agency", icon: Smartphone, label: "Uwakala (mobile money & bank agency)", description: "Morning and evening counts of cash and floats (M-Pesa, Mixx by Yas, Airtel Money, HaloPesa, NMB, CRDB, NBC ...), shortages, commissions and reports." },
  { key: "shifts", icon: CalendarClock, label: "Shifts", description: "Plan who works when, staff start and end their shifts, ask colleagues to cover and hand over with notes." },
];

/** Settings → Optional features: modules only some businesses need. */
export function FeaturesEditor({ value, onChange, disabled }: { value: string[]; onChange: (v: string[]) => void; disabled: boolean }) {
  const t = useT();
  return (
    <div className="space-y-4">
      {FEATURES.map((f) => (
        <div key={f.key} className="flex gap-3 rounded-lg p-4 ring-1 ring-ink-200">
          <f.icon className="mt-0.5 size-6 shrink-0 text-brand-600" />
          <div className="flex-1">
            <Switch label={t(f.label)} description={t(f.description)} checked={value.includes(f.key)} disabled={disabled}
              onChange={(on) => onChange(on ? [...value, f.key] : value.filter((x) => x !== f.key))} />
          </div>
        </div>
      ))}
      <p className="text-xs text-ink-500">{t("Switching a feature off hides it for everyone; its data is kept. Choose who may use it on the Roles page.")}</p>
    </div>
  );
}
