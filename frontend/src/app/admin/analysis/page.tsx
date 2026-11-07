"use client";

import type { ApiResponse } from "@/lib/api";
import { date, money, qty } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { ErrorState, PageHeader, Panel, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Row = { invoices: number; net_sales: number; quantity: number; credit_given: number; expenses: number; gross_profit?: number; net_profit?: number; from: string; to: string };

const groups: Array<{ title: string; keys: Array<[string, string]> }> = [
  { title: "Days and weeks", keys: [["today", "Today"], ["yesterday", "Yesterday"], ["this_week", "This week"], ["last_week", "Last week"]] },
  { title: "Months", keys: [["this_month", "This month"], ["last_month", "Last month"]] },
  { title: "Quarters", keys: [["q1", "Jan – Mar"], ["q2", "Apr – Jun"], ["q3", "Jul – Sep"], ["q4", "Oct – Dec"]] },
  { title: "Half years and year", keys: [["h1", "Jan – Jun"], ["h2", "Jul – Dec"], ["this_year", "This year"], ["all_time", "All time"]] },
];

export default function AnalysisPage() {
  const t = useT();
  const q = useApi<ApiResponse<Record<string, Row>>>("admin/dashboard/periods");
  const data = q.data?.data;
  const profit = data && data.today?.gross_profit !== undefined;

  return (
    <Guard perm="dashboard.view_financials">
      <PageHeader title={t("Business analysis")} description={t("Sales, quantities, profit and expenses side by side for every period, for the branch you have selected.")} />
      {q.isLoading && <Spinner variant="dashboard" />}
      {q.error && <ErrorState message={q.error.message} />}
      {data && (
        <div className="space-y-5">
          {groups.map((g) => (
            <Panel key={g.title} title={t(g.title)} padded={false}>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-ink-200 bg-ink-50/60 text-left text-[12.5px] text-ink-600">
                      <th className="px-4 py-2.5 font-semibold">{t("Period")}</th>
                      <th className="px-3 py-2.5 text-right font-semibold">{t("Receipts")}</th>
                      <th className="px-3 py-2.5 text-right font-semibold">{t("Qty sold")}</th>
                      <th className="px-3 py-2.5 text-right font-semibold">{t("Net sales")}</th>
                      <th className="px-3 py-2.5 text-right font-semibold">{t("Credit given")}</th>
                      {profit && <th className="px-3 py-2.5 text-right font-semibold">{t("Gross profit")}</th>}
                      <th className="px-3 py-2.5 text-right font-semibold">{t("Expenses")}</th>
                      {profit && <th className="px-4 py-2.5 text-right font-semibold">{t("Net profit")}</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {g.keys.map(([key, label]) => {
                      const r = data[key];
                      if (!r) return null;
                      return (
                        <tr key={key} className="border-b border-ink-100 last:border-0">
                          <td className="px-4 py-2.5">
                            <span className="font-medium text-ink-900">{t(label)}</span>
                            <span className="block text-xs text-ink-500">{t("{from} to {to}", { from: date(r.from), to: date(r.to) })}</span>
                          </td>
                          <td className="num px-3 py-2.5 text-right">{r.invoices.toLocaleString()}</td>
                          <td className="num px-3 py-2.5 text-right">{qty(r.quantity)}</td>
                          <td className="num px-3 py-2.5 text-right font-semibold">{money(r.net_sales)}</td>
                          <td className="num px-3 py-2.5 text-right">{money(r.credit_given)}</td>
                          {profit && <td className="num px-3 py-2.5 text-right">{money(r.gross_profit)}</td>}
                          <td className="num px-3 py-2.5 text-right">{money(r.expenses)}</td>
                          {profit && <td className={`num px-4 py-2.5 text-right font-semibold ${(r.net_profit ?? 0) < 0 ? "text-ember-600" : "text-brand-700"}`}>{money(r.net_profit)}</td>}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </Panel>
          ))}
        </div>
      )}
    </Guard>
  );
}
