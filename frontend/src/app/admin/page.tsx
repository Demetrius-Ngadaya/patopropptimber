"use client";

import Link from "next/link";
import { useState } from "react";
import { AlertTriangle, ArrowLeftRight, CalendarClock, PackageX, ShoppingBag, TimerOff, TrendingDown } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money, qty, toNumber, cn } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { paymentMethodLabels } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { MonthlyChart, RankBars } from "@/features/reports/components/MonthlyChart";
import { InsightsPanel } from "@/features/ai/components/InsightsPanel";
import { DataTable, ErrorState, PageHeader, Panel, Select, Spinner, Stat, StatusBadge } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Summary = { invoices: number; net_sales: number; quantity: number; credit_given: number; expenses: number; gross_profit?: number; net_profit?: number; collected?: number };
type Dashboard = {
  alerts: Record<string, number>;
  kpis: { today: Summary; month: Summary; last_month: Summary; receivables: number; debtors: number } | null;
  monthly: Array<Summary & { month: string }> | null;
  top_products: Array<{ name: string; quantity: number; revenue: number }> | null;
  payment_methods: Array<{ method: string; amount: number }> | null;
  recent_sales: Array<{ id: number; number: string; sold_at: string; customer_name: string | null; total: string; payment_status: string; status: string; cashier?: { name: string } }>;
  branches: Array<{ name: string; invoices: number; net_sales: number }> | null;
  show_profit: boolean;
};

const alertDefs = [
  { key: "pending_orders", label: "Open orders", href: "/admin/orders?status=open", icon: ShoppingBag, tone: "blue" },
  { key: "incoming_transfers", label: "Transfers to receive", href: "/admin/transfers?direction=in&status=in_transit", icon: ArrowLeftRight, tone: "blue" },
  { key: "low_stock", label: "Low stock", href: "/admin/stock?type=low", icon: TrendingDown, tone: "saffron" },
  { key: "out_of_stock", label: "Out of stock", href: "/admin/stock?type=out", icon: PackageX, tone: "red" },
  { key: "expiring_batches", label: "Expiring soon", href: "/admin/stock?type=expiring", icon: CalendarClock, tone: "saffron" },
  { key: "expired_batches", label: "Expired in stock", href: "/admin/stock?type=expired", icon: TimerOff, tone: "red" },
  { key: "overdue_debts", label: "Overdue debts", href: "/admin/customers?filter=overdue", icon: AlertTriangle, tone: "red" },
] as const;

function change(t: (k: string, v?: Record<string, string | number>) => string, current: number, previous: number) {
  if (!previous) return null;
  const pct = ((current - previous) / previous) * 100;
  return t("{pct}% vs last month", { pct: `${pct >= 0 ? "+" : ""}${pct.toFixed(0)}` });
}

export default function DashboardPage() {
  const t = useT();
  const { user, can } = useAuth();
  const [year, setYear] = useState(new Date().getFullYear());
  const q = useApi<ApiResponse<Dashboard>>("admin/dashboard", { year });
  const d = q.data?.data;
  const hour = new Date().getHours();
  const greeting = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";

  return (
    <Guard perm="dashboard.view">
      <PageHeader title={`${greeting}, ${user?.name.split(" ")[0]}`} description={t("Here is how the business is doing.")} />
      {q.isLoading && <Spinner variant="dashboard" />}
      {q.error && <ErrorState message={q.error.message} onRetry={() => q.refetch()} />}
      {d && (
        <div className="space-y-5">
          <div className="flex flex-wrap gap-2">
            {alertDefs.map((a) => {
              const count = d.alerts[a.key] ?? 0;
              if (!count) return null;
              const tones = { blue: "bg-sky-50 text-sky-800 ring-sky-100", saffron: "bg-saffron-50 text-saffron-700 ring-saffron-100", red: "bg-ember-50 text-ember-700 ring-ember-100" };
              return (
                <Link key={a.key} href={a.href} className={cn("inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ring-1 hover:brightness-95", tones[a.tone])}>
                  <a.icon className="size-4" aria-hidden />
                  <span className="num font-semibold">{count}</span> {t(a.label)}
                </Link>
              );
            })}
            {Object.values(d.alerts).every((v) => !v) && <p className="text-sm text-ink-500">{t("No alerts. Stock, orders and debts all look fine.")}</p>}
          </div>

          {d.kpis && (
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Stat label={t("Sales today")} value={money(d.kpis.today.net_sales)} sub={t("{n} receipts, {amount} collected", { n: d.kpis.today.invoices, amount: money(d.kpis.today.collected) })} tone="brand" />
              <Stat label={t("Sales this month")} value={money(d.kpis.month.net_sales)} sub={change(t, d.kpis.month.net_sales, d.kpis.last_month.net_sales) ?? t("{n} receipts", { n: d.kpis.month.invoices })} />
              {d.show_profit ? (
                <Stat label={t("Net profit this month")} value={money(d.kpis.month.net_profit)} sub={t("Gross {gross} less expenses {expenses}", { gross: money(d.kpis.month.gross_profit), expenses: money(d.kpis.month.expenses) })} tone={toNumber(d.kpis.month.net_profit) < 0 ? "ember" : "default"} />
              ) : (
                <Stat label={t("Expenses this month")} value={money(d.kpis.month.expenses)} />
              )}
              <Stat label={t("Customers owe you")} value={money(d.kpis.receivables)} sub={t("{n} customers with debt", { n: d.kpis.debtors })} tone="saffron" />
            </div>
          )}

          <InsightsPanel />

          {d.monthly && (
            <div className="grid gap-5 lg:grid-cols-2">
              <Panel
                title={t("Net sales by month, {year}", { year: year })}
                actions={
                  <Select aria-label={t("Year")} className="h-8 w-24 text-[13px]" value={year} onChange={(e) => setYear(Number(e.target.value))}>
                    {[0, 1, 2, 3].map((n) => new Date().getFullYear() - n).map((y) => <option key={y} value={y}>{y}</option>)}
                  </Select>
                }
              >
                <MonthlyChart data={d.monthly} dataKey="net_sales" color="#157a55" label={t("Net sales")} />
              </Panel>
              <Panel title={d.show_profit ? t("Net profit by month, {year}", { year: year }) : t("Expenses by month, {year}", { year: year })}>
                <MonthlyChart data={d.monthly} dataKey={d.show_profit ? "net_profit" : "expenses"} color={d.show_profit ? "#157a55" : "#c2850c"} label={d.show_profit ? t("Net profit") : t("Expenses")} />
              </Panel>
            </div>
          )}

          <div className="grid gap-5 lg:grid-cols-3">
            {d.top_products && (
              <Panel title={t("Best sellers this month")}>
                {d.top_products.length ? (
                  <RankBars rows={d.top_products.map((p) => ({ label: p.name, value: p.revenue, sub: `${qty(p.quantity)} sold` }))} />
                ) : <p className="text-sm text-ink-500">{t("No sales yet this month.")}</p>}
              </Panel>
            )}
            {d.payment_methods && (
              <Panel title={t("Money received this month")}>
                {d.payment_methods.length ? (
                  <RankBars rows={d.payment_methods.map((p) => ({ label: t(paymentMethodLabels[p.method] ?? p.method), value: p.amount }))} />
                ) : <p className="text-sm text-ink-500">{t("No payments yet this month.")}</p>}
              </Panel>
            )}
            {d.branches && (
              <Panel title={t("Branches this month")}>
                <RankBars rows={d.branches.map((b) => ({ label: b.name, value: b.net_sales, sub: t("{n} receipts", { n: b.invoices }) }))} />
              </Panel>
            )}
          </div>

          {can("sales.view") && (
            <Panel title={t("Latest sales")} padded={false} actions={<Link href="/admin/sales" className="text-sm font-medium text-brand-700 hover:underline">{t("All sales")}</Link>}>
              <DataTable
                rows={d.recent_sales}
                rowKey={(r) => r.id}
                columns={[
                  { key: "number", header: t("Receipt"), render: (r) => <Link href={`/admin/sales/${r.id}`} className="font-medium text-ink-900 hover:underline">{r.number}</Link> },
                  { key: "sold_at", header: t("Time"), render: (r) => dateTime(r.sold_at) },
                  { key: "customer_name", header: t("Customer"), render: (r) => r.customer_name ?? t("Customer") },
                  { key: "cashier.name", header: t("Cashier"), hideOnMobile: true },
                  { key: "total", header: t("Total"), align: "right", render: (r) => money(r.total) },
                  { key: "payment_status", header: t("Payment"), render: (r) => <StatusBadge status={r.status === "cancelled" ? "cancelled" : r.payment_status} /> },
                ]}
                empty={{ title: t("No sales yet"), message: t("Sales made at the till will show here.") }}
              />
            </Panel>
          )}
        </div>
      )}
    </Guard>
  );
}
