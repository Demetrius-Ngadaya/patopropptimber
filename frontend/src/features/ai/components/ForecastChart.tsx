"use client";

import { Area, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { formatDate } from "@/lib/format";
import { useT } from "@/lib/i18n";
import type { Forecast } from "../types";

/** Last 60 days of sales (solid) and the forecast (dashed) with its likely range (shaded). */
export function ForecastChart({ data }: { data: Forecast }) {
  const t = useT();
  const history = data.history.slice(-60).map((h) => ({ date: h.date, sold: h.quantity }));
  const future = data.forecast.map((f) => ({ date: f.date, forecast: f.quantity, range: [f.low, f.high] as [number, number] }));
  const rows = [...history, ...future];
  const fmt = (d: string) => formatDate(d, { day: "numeric", month: "short" });
  return (
    <div className="h-64 w-full" role="img" aria-label={t("Sales history and forecast")}>
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={rows} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--color-ink-100)" />
          <XAxis dataKey="date" tickFormatter={fmt} tickLine={false} axisLine={false} minTickGap={28} tick={{ fontSize: 11, fill: "var(--color-ink-500)" }} />
          <YAxis tickLine={false} axisLine={false} width={44} tick={{ fontSize: 11, fill: "var(--color-ink-500)" }} />
          <Tooltip labelFormatter={(d) => fmt(String(d))} contentStyle={{ borderRadius: 8, border: "1px solid var(--color-ink-200)", background: "var(--color-surface)", color: "var(--color-ink-900)", fontSize: 12 }}
            formatter={(v, name) => [Array.isArray(v) ? `${v[0]} – ${v[1]}` : String(v), name === "sold" ? t("Sold") : name === "forecast" ? t("Forecast") : t("Likely range")]} />
          <Area dataKey="range" stroke="none" fill="var(--color-saffron-300)" fillOpacity={0.35} isAnimationActive={false} />
          <Line dataKey="sold" stroke="var(--color-brand-600)" strokeWidth={2} dot={false} isAnimationActive={false} />
          <Line dataKey="forecast" stroke="var(--color-saffron-600)" strokeWidth={2} strokeDasharray="5 4" dot={false} isAnimationActive={false} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}
