"use client";

import { useState } from "react";
import { AlertOctagon, AlertTriangle, CalendarClock, CircleDollarSign, Clock3, PackageMinus, Percent, ShieldCheck, TrendingDown, XCircle } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { cn, date, money } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, EmptyState, PageHeader, Panel, Select, Spinner, Stat } from "@/components/ui";
import type { Anomaly } from "@/features/ai/types";

type Scan = { period: { from: string; to: string; days: number }; anomalies: Anomaly[]; summary: { high: number; medium: number; low: number } };
const ICONS: Record<string, typeof AlertTriangle> = {
  cancellations: XCircle, discounts: Percent, below_cost: CircleDollarSign, stock_loss: PackageMinus, stock_loss_user: PackageMinus,
  low_revenue: TrendingDown, after_hours: Clock3, backdated: CalendarClock,
};
const SEVERITY = { high: { tone: "red", ring: "ring-ember-200", icon: "bg-ember-50 text-ember-600", label: "High" }, medium: { tone: "saffron", ring: "ring-saffron-100", icon: "bg-saffron-50 text-saffron-700", label: "Medium" }, low: { tone: "neutral", ring: "ring-ink-200/70", icon: "bg-ink-100 text-ink-600", label: "Low" } } as const;

export default function AnomaliesPage() {
  const t = useT();
  const [days, setDays] = useState(30);
  const [severity, setSeverity] = useState<"" | Anomaly["severity"]>("");
  const q = useApi<ApiResponse<Scan>>("admin/ai/anomalies", { days });
  const d = q.data?.data;
  const rows = (d?.anomalies ?? []).filter((a) => !severity || a.severity === severity);

  return (
    <Guard perm="ai.anomalies">
      <PageHeader title={t("Fraud & anomaly alerts")} description={t("Scans sales, discounts, cancellations and stock for patterns that often mean mistakes or theft. An alert is a reason to look, not proof of wrongdoing.")}
        actions={<Select value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label={t("Period")} className="w-40">{[7, 30, 60, 90, 180].map((n) => <option key={n} value={n}>{t("Last {n} days", { n })}</option>)}</Select>} />
      {!d ? <Spinner /> : (
        <>
          <div className="mb-5 grid grid-cols-3 gap-3">
            {(["high", "medium", "low"] as const).map((s) => (
              <button key={s} type="button" onClick={() => setSeverity(severity === s ? "" : s)} className={cn("text-left", severity === s && "rounded-[var(--radius-panel)] ring-2 ring-brand-500")}>
                <Stat label={t("{level} priority", { level: t(SEVERITY[s].label) })} value={d.summary[s]} tone={s === "high" ? "ember" : s === "medium" ? "saffron" : "default"} />
              </button>
            ))}
          </div>
          {!rows.length ? (
            <Panel><EmptyState icon={<ShieldCheck className="size-10 text-brand-500" />} title={t("Nothing suspicious found")} message={t("Checked {from} to {to}.", { from: date(d.period.from), to: date(d.period.to) })} /></Panel>
          ) : (
            <ul className="space-y-3">
              {rows.map((a, i) => {
                const Icon = ICONS[a.type] ?? AlertOctagon;
                const sv = SEVERITY[a.severity];
                return (
                  <li key={`${a.type}-${i}`} className={cn("flex gap-4 rounded-[var(--radius-panel)] bg-surface p-4 ring-1", sv.ring)}>
                    <span className={cn("grid size-10 shrink-0 place-items-center rounded-full", sv.icon)}><Icon className="size-5" /></span>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="font-semibold text-ink-900">{a.title}</p>
                        <Badge tone={sv.tone}>{t(sv.label)}</Badge>
                        {a.amount > 0 && <span className="num ml-auto text-sm font-semibold text-ink-700">{money(a.amount)}</span>}
                      </div>
                      <p className="mt-1 text-sm text-ink-600">{a.detail}</p>
                      <p className="mt-2 text-xs text-ink-500"><span className="font-medium text-ink-700">{t("What to do")}:</span> {a.action}</p>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </>
      )}
    </Guard>
  );
}
