"use client";

import { useEffect, useMemo, useState } from "react";
import { Plus, Save } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, money, toNumber, formatDate } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, EmptyState, Field, NumericInput, PageHeader, Panel, Select, Spinner, Tabs } from "@/components/ui";
import { AccountSelect, useAccounts } from "@/features/accounting/components/AccountSelect";
import type { FiscalYear } from "@/features/accounting/types";

type BudgetRow = { account_id: number; month: number; amount: string; account?: { id: number; code: string; name: string } };
type VsActual = { rows: Array<{ account_id: number; code: string; name: string; budget: number; actual: number; variance: number; used_percent: number | null; months: Array<{ month: number; budget: number; actual: number }> }> };

const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);

export default function BudgetsPage() {
  const t = useT();
  const { can } = useAuth();
  const years = useApi<ApiResponse<FiscalYear[]>>("admin/accounting/fiscal-years");
  const [yearId, setYearId] = useState<number | "">("");
  const [tab, setTab] = useState<"plan" | "actual">("actual");
  const year = years.data?.data.find((y) => y.id === yearId);
  useEffect(() => {
    if (!yearId && years.data?.data.length) {
      const now = new Date().toISOString().slice(0, 10);
      setYearId((years.data.data.find((y) => y.start_date <= now && y.end_date >= now) ?? years.data.data[0]).id);
    }
  }, [years.data, yearId]);

  const monthLabel = (m: number) => {
    if (!year) return String(m);
    const [y, mo] = year.start_date.slice(0, 7).split("-").map(Number);
    return formatDate(new Date(y, mo - 1 + m - 1, 1), { month: "short" });
  };

  return (
    <Guard perm={["accounting.budgets", "accounting.statements"]}>
      <PageHeader title={t("Budgets")} description={t("Plan income and spending per account and month, then compare with what really happened.")} />
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <Field label={t("Financial year")}>{(p) => <Select {...p} className="w-48" value={yearId} onChange={(e) => setYearId(Number(e.target.value))}>{years.data?.data.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}</Select>}</Field>
        <Tabs value={tab} onChange={setTab} tabs={[{ value: "actual", label: t("Budget vs actual") }, ...(can("accounting.budgets") ? [{ value: "plan" as const, label: t("Edit budget") }] : [])]} />
      </div>
      {!yearId ? <Spinner /> : tab === "plan" ? <BudgetEditor yearId={yearId} locked={year?.status === "closed"} monthLabel={monthLabel} /> : <BudgetVsActual yearId={yearId} monthLabel={monthLabel} />}
    </Guard>
  );
}

function BudgetEditor({ yearId, locked, monthLabel }: { yearId: number; locked: boolean; monthLabel: (m: number) => string }) {
  const t = useT();
  const accounts = useAccounts({ postable: true });
  const q = useApi<ApiResponse<BudgetRow[]>>(`admin/accounting/fiscal-years/${yearId}/budgets`);
  const [grid, setGrid] = useState<Record<number, string[]>>({});
  const [adding, setAdding] = useState<number | "">("");
  useEffect(() => {
    const g: Record<number, string[]> = {};
    for (const b of q.data?.data ?? []) {
      g[b.account_id] ??= Array(12).fill("");
      g[b.account_id][b.month - 1] = String(toNumber(b.amount));
    }
    setGrid(g);
  }, [q.data]);
  const save = useAction(() => api.put(`admin/accounting/fiscal-years/${yearId}/budgets`, { rows: Object.entries(grid).map(([account_id, months]) => ({ account_id: Number(account_id), months: months.map((m) => (m === "" ? null : Number(m))) })) }), { invalidate: [[`admin/accounting/fiscal-years/${yearId}/budgets`], [`admin/accounting/fiscal-years/${yearId}/budget-vs-actual`]] });
  const name = (id: number) => { const a = accounts.find((x) => x.id === id); return a ? `${a.code} ${a.name}` : String(id); };

  if (q.isLoading) return <Spinner variant="page" />;
  return (
    <Panel padded={false} actions={!locked && <Button icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save budget")}</Button>} title={locked ? t("This year is closed: budgets are read-only.") : t("Monthly amounts")}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-ink-50 text-xs text-ink-500"><tr><th className="sticky left-0 bg-ink-50 px-3 py-2 text-left font-medium">{t("Account")}</th>{MONTHS.map((m) => <th key={m} className="px-1 py-2 text-right font-medium">{monthLabel(m)}</th>)}<th className="px-3 py-2 text-right font-medium">{t("Year")}</th></tr></thead>
          <tbody className="divide-y divide-ink-100">
            {Object.entries(grid).map(([id, months]) => (
              <tr key={id}>
                <td className="sticky left-0 whitespace-nowrap bg-surface px-3 py-1.5">
                  {name(Number(id))}
                  {!locked && months[0] !== "" && <button type="button" className="ml-2 text-xs font-medium text-brand-700 hover:underline" onClick={() => setGrid((g) => ({ ...g, [id]: Array(12).fill(months[0]) }))}>{t("Same every month")}</button>}
                </td>
                {months.map((v, i) => (
                  <td key={i} className="px-0.5 py-1">
                    <NumericInput aria-label={`${name(Number(id))} ${monthLabel(i + 1)}`} disabled={locked} min={0} step="any" value={v}
                      onChange={(e) => setGrid((g) => ({ ...g, [id]: g[Number(id)].map((x, j) => (j === i ? e.target.value : x)) }))}
                      className="num h-8 w-24 rounded border border-ink-200 bg-surface px-1.5 text-right text-sm" />
                  </td>
                ))}
                <td className="num px-3 py-1.5 text-right font-semibold">{money(months.reduce((s, x) => s + toNumber(x), 0))}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!locked && (
        <div className="flex flex-wrap items-center gap-2 border-t border-ink-100 p-3">
          <AccountSelect aria-label={t("Account to add")} className="w-72" value={adding} onChange={setAdding} types={["income", "expense"]} />
          <Button variant="secondary" size="sm" icon={<Plus className="size-4" />} disabled={!adding || Boolean(adding && grid[adding])} onClick={() => { if (adding) setGrid((g) => ({ ...g, [adding]: Array(12).fill("") })); setAdding(""); }}>{t("Add account")}</Button>
        </div>
      )}
    </Panel>
  );
}

function BudgetVsActual({ yearId, monthLabel }: { yearId: number; monthLabel: (m: number) => string }) {
  const t = useT();
  const q = useApi<ApiResponse<VsActual>>(`admin/accounting/fiscal-years/${yearId}/budget-vs-actual`);
  const rows = useMemo(() => q.data?.data.rows ?? [], [q.data]);
  if (q.isLoading) return <Spinner variant="page" />;
  if (!rows.length) return <Panel><EmptyState title={t("No budget for this year yet")} message={t("Open \"Edit budget\" to plan amounts per account.")} /></Panel>;
  return (
    <Panel padded={false}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-ink-50 text-xs text-ink-500"><tr><th className="px-3 py-2 text-left font-medium">{t("Account")}</th><th className="px-3 py-2 text-right font-medium">{t("Budget")}</th><th className="px-3 py-2 text-right font-medium">{t("Actual")}</th><th className="px-3 py-2 text-right font-medium">{t("Difference")}</th><th className="w-1/3 px-3 py-2 text-left font-medium">{t("Used")}</th></tr></thead>
          <tbody className="divide-y divide-ink-100">
            {rows.map((r) => {
              const pct = r.used_percent ?? 0;
              return (
                <tr key={r.account_id}>
                  <td className="px-3 py-2">{r.code} {r.name}<span className="block text-[11px] text-ink-400">{r.months.filter((m) => m.actual > 0).length ? r.months.map((m) => `${monthLabel(m.month)} ${Math.round(m.actual / 1000)}k`).slice(0, 4).join(" · ") : ""}</span></td>
                  <td className="num px-3 py-2 text-right">{money(r.budget)}</td>
                  <td className="num px-3 py-2 text-right">{money(r.actual)}</td>
                  <td className={cn("num px-3 py-2 text-right", r.variance > 0 ? "text-ember-600" : "text-brand-700")}>{r.variance > 0 ? "+" : ""}{money(r.variance)}</td>
                  <td className="px-3 py-2">
                    <div className="flex items-center gap-2">
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-ink-100"><div className={cn("h-full rounded-full", pct > 100 ? "bg-ember-500" : pct > 85 ? "bg-saffron-500" : "bg-brand-500")} style={{ width: `${Math.min(100, pct)}%` }} /></div>
                      <span className="num w-12 text-right text-xs text-ink-600">{r.used_percent === null ? "—" : `${pct}%`}</span>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}
