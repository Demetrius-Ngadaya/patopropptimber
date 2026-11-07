"use client";

import { useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { ArrowDownRight, ArrowRight, ArrowUpRight, LineChart, Truck } from "lucide-react";
import Link from "next/link";
import type { ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, qty } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Modal, PageHeader, Panel, SearchInput, Spinner, Stat, Tabs } from "@/components/ui";
import { ForecastChart } from "@/features/ai/components/ForecastChart";
import { REORDER_LABEL, REORDER_TONE, type Forecast, type ReorderRow, type ReorderStatus } from "@/features/ai/types";

type Plan = { rows: ReorderRow[]; summary: { out_of_stock: number; reorder_now: number; reorder_soon: number; dead_stock: number; suggested_order_cost: number } };
const TREND = { rising: ArrowUpRight, falling: ArrowDownRight, steady: ArrowRight };

function ForecastModal({ row, onClose }: { row: ReorderRow | null; onClose: () => void }) {
  const t = useT();
  const q = useApi<ApiResponse<Forecast>>(row ? `admin/ai/forecast/${row.product_id}` : null, { horizon: 30 }, { keepPrevious: false });
  if (!row) return null;
  const s = q.data?.data.summary;
  const days = [t("Sun"), t("Mon"), t("Tue"), t("Wed"), t("Thu"), t("Fri"), t("Sat")];
  return (
    <Modal open onClose={onClose} size="lg" title={row.name} description={t("Daily sales for the last 60 days and the next 30 days forecast.")}>
      {!q.data ? <Spinner /> : (
        <>
          <ForecastChart data={q.data.data} />
          <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Stat label={t("Sold per day (avg)")} value={qty(s!.avg_daily_sales, row.unit)} />
            <Stat label={t("Expected per day")} value={qty(s!.forecast_daily, row.unit)} tone="saffron" />
            <Stat label={t("Trend")} value={t(s!.trend)} />
            <Stat label={t("Confidence")} value={t(s!.confidence)} />
          </div>
          {s!.weekday_profile?.length === 7 && (
            <div className="mt-4">
              <p className="mb-2 text-xs font-medium uppercase text-ink-500">{t("Busiest days")}</p>
              <div className="flex items-end gap-1.5">
                {s!.weekday_profile.map((v, i) => (
                  <div key={i} className="flex flex-1 flex-col items-center gap-1">
                    <div className="w-full rounded-t bg-brand-500/80" style={{ height: `${Math.max(4, Math.min(64, v * 32))}px` }} title={`${Math.round(v * 100)}%`} />
                    <span className="text-[11px] text-ink-500">{days[i]}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </Modal>
  );
}

function Content() {
  const t = useT();
  const { can } = useAuth();
  const initial = (useSearchParams().get("status") as ReorderStatus | null) ?? "";
  const [status, setStatus] = useState<string>(initial);
  const [search, setSearch] = useState("");
  const [chart, setChart] = useState<ReorderRow | null>(null);
  const q = useApi<ApiResponse<Plan>>("admin/ai/reorder", { status: status || undefined, search: useDebounced(search) || undefined });
  const s = q.data?.data.summary;
  const urgent = status === "" ? q.data?.data.rows.filter((r) => r.status !== "ok") : q.data?.data.rows;

  return (
    <>
      <PageHeader title={t("Reorder planner")} description={t("Forecasts each product's sales from its history (trend and day-of-week pattern), then suggests what to order so you neither run out nor over-stock.")}
        actions={can("purchases.create") && <Link href="/admin/purchases/new"><Button icon={<Truck className="size-4" />}>{t("New purchase")}</Button></Link>} />
      {s && (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label={t("Out of stock")} value={s.out_of_stock} tone="ember" />
          <Stat label={t("Order now")} value={s.reorder_now} tone="ember" />
          <Stat label={t("Order soon")} value={s.reorder_soon} tone="saffron" />
          <Stat label={t("Suggested order cost")} value={money(s.suggested_order_cost)} tone="brand" />
        </div>
      )}
      <Panel padded={false}>
        <div className="px-3 pt-2"><Tabs value={status} onChange={setStatus} tabs={[{ value: "", label: t("Needs attention") }, ...(["out_of_stock", "reorder_now", "reorder_soon", "dead_stock", "overstock"] as ReorderStatus[]).map((v) => ({ value: v, label: t(REORDER_LABEL[v]) }))]} /></div>
        <div className="border-b border-ink-100 p-3"><SearchInput value={search} onChange={setSearch} placeholder={t("Find product")} className="w-full max-w-sm" /></div>
        <DataTable rows={urgent} loading={q.isFetching} rowKey={(r) => r.product_id} onRowClick={setChart}
          columns={[
            { key: "name", header: t("Product"), render: (r) => <span className="font-medium">{r.name}<span className="block text-xs font-normal text-ink-500">{r.sku}</span></span> },
            { key: "on_hand", header: t("In stock"), align: "right", render: (r) => <span className="num">{qty(r.on_hand, r.unit)}</span> },
            { key: "forecast_daily", header: t("Sells per day"), align: "right", hideOnMobile: true, render: (r) => {
              const Icon = TREND[r.trend];
              return <span className="num inline-flex items-center gap-1">{r.forecast_daily}<Icon className={`size-3.5 ${r.trend === "rising" ? "text-brand-600" : r.trend === "falling" ? "text-ember-600" : "text-ink-400"}`} /></span>;
            } },
            { key: "days_of_cover", header: t("Lasts"), align: "right", render: (r) => r.days_of_cover === null ? "—" : <span className="num">{t("{days} days", { days: Math.floor(r.days_of_cover) })}{r.stockout_date && <span className="block text-xs text-ink-500">{t("until {date}", { date: date(r.stockout_date) })}</span>}</span> },
            { key: "suggested_order", header: t("Order"), align: "right", render: (r) => r.suggested_order > 0 ? <span className="num font-semibold">{qty(r.suggested_order, r.unit)}<span className="block text-xs font-normal text-ink-500">{money(r.suggested_order_cost)}</span></span> : "—" },
            { key: "status", header: t("Status"), render: (r) => <span className="flex flex-col items-start gap-0.5"><Badge tone={REORDER_TONE[r.status]}>{t(REORDER_LABEL[r.status])}</Badge>{r.confidence === "low" && <span className="text-[11px] text-ink-400">{t("little history")}</span>}</span> },
            { key: "_c", header: "", align: "right", render: () => <LineChart className="size-4 text-ink-400" /> },
          ]}
          empty={{ title: t("Nothing to reorder"), message: t("Stock covers the expected sales for every product.") }} />
      </Panel>
      <ForecastModal row={chart} onClose={() => setChart(null)} />
    </>
  );
}

export default function ReorderPage() {
  return <Guard perm="ai.forecast"><Suspense><Content /></Suspense></Guard>;
}
