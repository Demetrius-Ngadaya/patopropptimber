"use client";

import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { money } from "@/lib/format";
import { useT } from "@/lib/i18n";

/** Single-series monthly bars. One measure per chart (no dual axes). */
export function MonthlyChart({ data, dataKey, color, label }: { data: Array<Record<string, number | string>>; dataKey: string; color: string; label: string }) {
  const t = useT();
  const compact = (v: number) => (Math.abs(v) >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)}M` : Math.abs(v) >= 1000 ? `${Math.round(v / 1000)}k` : String(v));
  return (
    <div className="h-56 w-full" role="img" aria-label={t("{label} by month", { label: label })}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 4, left: -8, bottom: 0 }} barCategoryGap="22%">
          <CartesianGrid vertical={false} stroke="var(--color-ink-100)" />
          <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fontSize: 12, fill: "var(--color-ink-500)" }} />
          <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 12, fill: "var(--color-ink-500)" }} tickFormatter={compact} width={48} />
          <Tooltip
            cursor={{ fill: "rgba(19,33,59,0.05)" }}
            formatter={(v) => [money(v as number), label]}
            contentStyle={{ borderRadius: 8, border: "1px solid var(--color-ink-200)", background: "var(--color-surface)", color: "var(--color-ink-900)", fontSize: 13 }}
          />
          <Bar dataKey={dataKey} fill={color} radius={[4, 4, 0, 0]} maxBarSize={36} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

/** Horizontal bars in plain HTML, labelled directly (used for rankings). */
export function RankBars({ rows, valueLabel = (v: number) => money(v) }: { rows: Array<{ label: string; value: number; sub?: string }>; valueLabel?: (v: number) => string }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <ul className="space-y-2.5">
      {rows.map((r) => (
        <li key={r.label}>
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span className="truncate text-ink-800">{r.label}</span>
            <span className="num shrink-0 font-medium text-ink-900">{valueLabel(r.value)}</span>
          </div>
          <div className="mt-1 h-2 rounded-full bg-ink-100">
            <div className="h-2 rounded-full bg-brand-500" style={{ width: `${Math.max(2, (r.value / max) * 100)}%` }} title={`${r.label}: ${valueLabel(r.value)}`} />
          </div>
          {r.sub && <p className="mt-0.5 text-xs text-ink-500">{r.sub}</p>}
        </li>
      ))}
    </ul>
  );
}
