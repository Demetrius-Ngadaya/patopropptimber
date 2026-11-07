"use client";

import Link from "next/link";
import { ArrowDownLeft, ArrowUpRight, HandCoins, PiggyBank, ShoppingCart, Undo2 } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { cn, date, dateTime, money } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Badge, EmptyState, Panel, Spinner, Stat } from "@/components/ui";

export type TimelineRow = {
  date: string;
  kind: string;
  reference: string;
  sale_number: string | null;
  amount: number;
  purchase_amount: number | null;
  debt_amount: number | null;
  savings_before: number | null;
  savings_after: number | null;
  debt_before: number | null;
  debt_after: number | null;
  due_date?: string | null;
  method: string | null;
  notes: string | null;
};

export type FinancialHistoryData = {
  customer: { id: number; name: string; phone: string | null; address: string | null };
  savings: { account_id: number; account_no: string; status: string; total_deposited: number; total_spent: number; total_withdrawn: number; balance: number } | null;
  credit: { sales: number; original_owed: number; repaid: number; outstanding: number; overdue: number; next_due_date: string | null };
  net_position: number;
  timeline: TimelineRow[];
};

const KIND: Record<string, { label: string; icon: typeof PiggyBank; tone: string; sign: 1 | -1 | 0 }> = {
  savings_deposit: { label: "Deposit", icon: ArrowDownLeft, tone: "text-brand-700 bg-brand-50", sign: 1 },
  savings_purchase: { label: "Purchase from savings", icon: ShoppingCart, tone: "text-sky-700 bg-sky-50", sign: -1 },
  savings_withdrawal: { label: "Withdrawal", icon: ArrowUpRight, tone: "text-saffron-700 bg-saffron-50", sign: -1 },
  savings_refund: { label: "Refund to savings", icon: Undo2, tone: "text-brand-700 bg-brand-50", sign: 1 },
  savings_debt_payment: { label: "Debt paid from savings", icon: HandCoins, tone: "text-sky-700 bg-sky-50", sign: -1 },
  savings_reversal: { label: "Deposit reversed", icon: Undo2, tone: "text-ember-700 bg-ember-50", sign: -1 },
  credit_sale: { label: "Credit sale (debt)", icon: ShoppingCart, tone: "text-ember-700 bg-ember-50", sign: 0 },
  debt_payment: { label: "Debt repayment", icon: HandCoins, tone: "text-brand-700 bg-brand-50", sign: 0 },
};

/**
 * One customer's whole money story: savings deposits and spending, credit
 * sales and every repayment, each with the balances recorded at that moment.
 */
export function FinancialHistory({ customerId }: { customerId: number }) {
  const t = useT();
  const q = useApi<ApiResponse<FinancialHistoryData>>(`admin/customers/${customerId}/financial-history`);
  const d = q.data?.data;
  if (q.isLoading || !d) return <Spinner variant="page" />;

  const rows = [...d.timeline].reverse();

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={t("Total deposited")} value={money(d.savings?.total_deposited ?? 0)} sub={d.savings ? d.savings.account_no : t("No savings account")} />
        <Stat label={t("Spent from savings")} value={money(d.savings?.total_spent ?? 0)} />
        <Stat label={t("Savings balance")} value={money(d.savings?.balance ?? 0)} tone="brand" />
        <Stat label={t("Outstanding debt")} value={money(d.credit.outstanding)} tone={d.credit.outstanding > 0 ? "saffron" : "default"} sub={d.credit.overdue > 0 ? <span className="text-ember-600">{t("{amount} overdue", { amount: money(d.credit.overdue) })}</span> : d.credit.next_due_date ? t("Next due {date}", { date: date(d.credit.next_due_date) }) : undefined} />
      </div>
      <p className={cn("rounded-lg px-4 py-3 text-sm font-medium", d.net_position >= 0 ? "bg-brand-50 text-brand-800" : "bg-saffron-50 text-saffron-700")}>
        {d.net_position >= 0
          ? t("Overall, the business holds {amount} of this customer's money.", { amount: money(d.net_position) })
          : t("Overall, this customer owes the business {amount}.", { amount: money(-d.net_position) })}
      </p>

      <Panel title={t("Transaction history")} padded={false}>
        {!rows.length ? <EmptyState icon={<PiggyBank className="size-9" />} title={t("No transactions yet")} /> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-ink-50 text-left text-xs font-medium text-ink-500">
                <tr>
                  <th className="px-3 py-2">{t("Date")}</th>
                  <th className="px-3 py-2">{t("What happened")}</th>
                  <th className="px-3 py-2 text-right">{t("Amount")}</th>
                  <th className="px-3 py-2 text-right">{t("Purchase")}</th>
                  <th className="px-3 py-2 text-right">{t("Savings after")}</th>
                  <th className="px-3 py-2 text-right">{t("Debt after")}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {rows.map((r, i) => {
                  const k = KIND[r.kind] ?? { label: r.kind, icon: PiggyBank, tone: "bg-ink-100 text-ink-700", sign: 0 as const };
                  const Icon = k.icon;
                  return (
                    <tr key={`${r.reference}-${i}`} className="align-top">
                      <td className="whitespace-nowrap px-3 py-2.5 text-ink-600">{dateTime(r.date)}</td>
                      <td className="px-3 py-2.5">
                        <span className="flex items-center gap-2">
                          <span className={cn("grid size-7 shrink-0 place-items-center rounded-full", k.tone)}><Icon className="size-3.5" /></span>
                          <span>
                            <span className="font-medium text-ink-900">{t(k.label)}</span>
                            <span className="block text-xs text-ink-500">
                              {r.sale_number ? <Link href={`/admin/sales?search=${encodeURIComponent(r.sale_number)}`} className="hover:underline">{r.sale_number}</Link> : r.reference}
                              {r.kind === "credit_sale" && r.due_date && ` · ${t("pay by {date}", { date: date(r.due_date) })}`}
                              {r.notes && ` · ${r.notes}`}
                            </span>
                          </span>
                        </span>
                      </td>
                      <td className={cn("num whitespace-nowrap px-3 py-2.5 text-right font-semibold", k.sign > 0 ? "text-brand-700" : k.sign < 0 ? "text-ink-900" : "")}>
                        {k.sign > 0 ? "+" : k.sign < 0 ? "−" : ""}{money(r.amount)}
                      </td>
                      <td className="num px-3 py-2.5 text-right text-ink-600">{r.purchase_amount !== null ? money(r.purchase_amount) : ""}</td>
                      <td className="num px-3 py-2.5 text-right">{r.savings_after !== null ? money(r.savings_after) : ""}</td>
                      <td className="num px-3 py-2.5 text-right">
                        {r.debt_after !== null ? (r.debt_after > 0 ? <span className="text-ember-600">{money(r.debt_after)}</span> : <Badge tone="green">{t("Settled")}</Badge>) : r.debt_amount ? <span className="text-ember-600">{money(r.debt_amount)}</span> : ""}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </div>
  );
}
