"use client";

import Link from "next/link";
import { Fragment, useState } from "react";
import { CheckCircle2, Printer, TriangleAlert } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { cn, date, money, monthStart, today } from "@/lib/format";
import { useApi, useUrlParam } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { Guard } from "@/components/layout/Guard";
import { Button, Checkbox, PageHeader, Panel, Spinner, Tabs } from "@/components/ui";
import { DateRange, type Range } from "@/features/accounting/components/DateRange";
import { eventLabel, type StatementLine } from "@/features/accounting/types";

type Tab = "pnl" | "balance" | "cash" | "trial" | "vat" | "receivables" | "payables";

type PnL = {
  sections: Record<"revenue" | "cost_of_sales" | "other_income" | "operating_expenses", StatementLine[]>;
  totals: { revenue: number; cost_of_sales: number; gross_profit: number; gross_margin_percent: number | null; other_income: number; operating_expenses: number; net_profit: number; net_margin_percent: number | null };
  previous?: PnL;
  from: string;
  to: string;
};
type Balance = { as_of: string; sections: Record<"asset" | "liability" | "equity", StatementLine[]>; totals: { assets: number; liabilities: number; equity: number; liabilities_and_equity: number }; balanced: boolean };
type CashFlow = { opening_cash: number; closing_cash: number; net_change: number; totals: Record<"operating" | "investing" | "financing", number>; activities: Record<"operating" | "investing" | "financing", Array<{ event: string; inflow: number; outflow: number; net: number }>> };
type Trial = { rows: Array<{ account_id: number; code: string; name: string; type: string; opening_debit: number; opening_credit: number; period_debit: number; period_credit: number; debit: number; credit: number }>; totals: Record<string, number>; balanced: boolean };
type Vat = { taxable_sales: number; output_vat: number; input_vat: number; net_vat_payable: number };
type Aged = { rows: Array<{ party_id: number | null; name: string; phone: string | null; current: number; "1_30": number; "31_60": number; "61_90": number; over_90: number; total: number }>; totals: Record<string, number> };

const Amount = ({ v, strong, negativeRed }: { v: number; strong?: boolean; negativeRed?: boolean }) => (
  <span className={cn("num", strong && "font-semibold", negativeRed && v < 0 && "text-ember-600")}>{v < 0 ? `(${money(-v)})` : money(v)}</span>
);

function Row({ label, value, prev, strong, indent, href }: { label: React.ReactNode; value: number; prev?: number; strong?: boolean; indent?: boolean; href?: string }) {
  return (
    <tr className={cn(strong && "border-t border-ink-300")}>
      <td className={cn("py-1.5 pr-3", indent && "pl-4", strong && "font-semibold text-ink-950")}>{href ? <Link href={href} className="hover:underline">{label}</Link> : label}</td>
      <td className="py-1.5 text-right"><Amount v={value} strong={strong} negativeRed /></td>
      {prev !== undefined && <td className="py-1.5 pl-6 text-right text-ink-500"><Amount v={prev} strong={strong} /></td>}
    </tr>
  );
}

function ProfitAndLoss({ range }: { range: Range }) {
  const t = useT();
  const [compare, setCompare] = useState(false);
  const q = useApi<ApiResponse<PnL>>("admin/accounting/reports/profit-and-loss", { ...range, compare: compare ? 1 : undefined });
  const d = q.data?.data;
  if (!d) return <Spinner variant="page" />;
  const p = d.previous;
  const prevOf = (section: keyof PnL["sections"], id: number | null) => (p ? p.sections[section].find((l) => l.account_id === id)?.amount ?? 0 : undefined);
  const section = (key: keyof PnL["sections"], title: string, total: number, prevTotal?: number) => (
    <>
      <tr><td colSpan={3} className="pt-4 text-xs font-semibold uppercase tracking-wide text-ink-500">{t(title)}</td></tr>
      {d.sections[key].map((l) => <Row key={l.account_id} label={`${l.code} ${l.name}`} value={l.amount} prev={compare ? prevOf(key, l.account_id) : undefined} indent href={`/admin/accounting/accounts/${l.account_id}?date_from=${range.date_from}&date_to=${range.date_to}`} />)}
      <Row label={t("Total {section}", { section: t(title).toLowerCase() })} value={total} prev={compare ? prevTotal : undefined} strong />
    </>
  );
  return (
    <>
      <div className="no-print mb-3"><Checkbox label={t("Compare with the previous period")} checked={compare} onChange={(e) => setCompare(e.target.checked)} /></div>
      <table className="w-full max-w-3xl text-sm">
        {compare && p && <thead><tr className="text-xs text-ink-500"><th /><th className="text-right font-medium">{date(d.from)} – {date(d.to)}</th><th className="pl-6 text-right font-medium">{date(p.from)} – {date(p.to)}</th></tr></thead>}
        <tbody>
          {section("revenue", "Revenue", d.totals.revenue, p?.totals.revenue)}
          {section("cost_of_sales", "Cost of sales", d.totals.cost_of_sales, p?.totals.cost_of_sales)}
          <Row label={<>{t("Gross profit")} {d.totals.gross_margin_percent !== null && <span className="font-normal text-ink-500">({d.totals.gross_margin_percent}%)</span>}</>} value={d.totals.gross_profit} prev={compare ? p?.totals.gross_profit : undefined} strong />
          {section("other_income", "Other income", d.totals.other_income, p?.totals.other_income)}
          {section("operating_expenses", "Operating expenses", d.totals.operating_expenses, p?.totals.operating_expenses)}
          <tr className="border-t-2 border-double border-ink-400 text-base">
            <td className="py-2.5 font-bold">{d.totals.net_profit >= 0 ? t("Net profit") : t("Net loss")} {d.totals.net_margin_percent !== null && <span className="text-sm font-normal text-ink-500">({d.totals.net_margin_percent}%)</span>}</td>
            <td className="py-2.5 text-right"><Amount v={d.totals.net_profit} strong negativeRed /></td>
            {compare && p && <td className="py-2.5 pl-6 text-right text-ink-500"><Amount v={p.totals.net_profit} strong /></td>}
          </tr>
        </tbody>
      </table>
    </>
  );
}

function BalanceSheet({ asOf }: { asOf: string }) {
  const t = useT();
  const q = useApi<ApiResponse<Balance>>("admin/accounting/reports/balance-sheet", { as_of: asOf });
  const d = q.data?.data;
  if (!d) return <Spinner variant="page" />;
  const block = (key: keyof Balance["sections"], title: string, total: number) => (
    <>
      <tr><td colSpan={2} className="pt-4 text-xs font-semibold uppercase tracking-wide text-ink-500">{t(title)}</td></tr>
      {d.sections[key].map((l, i) => <Row key={l.account_id ?? `x${i}`} label={l.code ? `${l.code} ${l.name}` : t(l.name)} value={l.amount} indent href={l.account_id ? `/admin/accounting/accounts/${l.account_id}?date_to=${asOf}` : undefined} />)}
      <Row label={t("Total {section}", { section: t(title).toLowerCase() })} value={total} strong />
    </>
  );
  return (
    <>
      <Balanced ok={d.balanced} />
      <div className="grid gap-8 lg:grid-cols-2">
        <table className="w-full text-sm"><tbody>{block("asset", "Assets", d.totals.assets)}</tbody></table>
        <table className="w-full text-sm">
          <tbody>
            {block("liability", "Liabilities", d.totals.liabilities)}
            {block("equity", "Equity", d.totals.equity)}
            <Row label={t("Liabilities and equity")} value={d.totals.liabilities_and_equity} strong />
          </tbody>
        </table>
      </div>
    </>
  );
}

function Balanced({ ok }: { ok: boolean }) {
  const t = useT();
  return ok
    ? <p className="mb-4 inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700"><CheckCircle2 className="size-3.5" /> {t("The books balance")}</p>
    : <p className="mb-4 inline-flex items-center gap-1.5 rounded-full bg-ember-50 px-3 py-1 text-xs font-medium text-ember-700"><TriangleAlert className="size-3.5" /> {t("The books do not balance. Contact support.")}</p>;
}

function CashFlowView({ range }: { range: Range }) {
  const t = useT();
  const q = useApi<ApiResponse<CashFlow>>("admin/accounting/reports/cash-flow", range);
  const d = q.data?.data;
  if (!d) return <Spinner variant="page" />;
  const titles = { operating: "Operating activities", investing: "Investing activities", financing: "Financing activities" } as const;
  return (
    <table className="w-full max-w-3xl text-sm">
      <tbody>
        <Row label={t("Cash, bank and mobile money at the start")} value={d.opening_cash} strong />
        {(["operating", "investing", "financing"] as const).map((k) => (
          <Fragment key={k}>
            <tr><td colSpan={2} className="pt-4 text-xs font-semibold uppercase tracking-wide text-ink-500">{t(titles[k])}</td></tr>
            {d.activities[k].map((a) => <Row key={k + a.event} label={<>{t(eventLabel(a.event))} <span className="text-xs text-ink-400">{t("in {inflow} / out {outflow}", { inflow: money(a.inflow), outflow: money(a.outflow) })}</span></>} value={a.net} indent />)}
            <Row label={t("Net cash from {section}", { section: t(titles[k]).toLowerCase() })} value={d.totals[k]} strong />
          </Fragment>
        ))}
        <Row label={t("Net change in cash")} value={d.net_change} strong />
        <tr className="border-t-2 border-double border-ink-400 text-base"><td className="py-2.5 font-bold">{t("Cash, bank and mobile money at the end")}</td><td className="py-2.5 text-right"><Amount v={d.closing_cash} strong /></td></tr>
      </tbody>
    </table>
  );
}

function TrialBalance({ range }: { range: Range }) {
  const t = useT();
  const q = useApi<ApiResponse<Trial>>("admin/accounting/reports/trial-balance", range);
  const d = q.data?.data;
  if (!d) return <Spinner variant="page" />;
  return (
    <>
      <Balanced ok={d.balanced} />
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="text-xs text-ink-500">
            <tr className="border-b border-ink-200">
              <th className="py-2 text-left font-medium">{t("Account")}</th>
              <th className="py-2 text-right font-medium">{t("Opening Dr")}</th><th className="py-2 text-right font-medium">{t("Opening Cr")}</th>
              <th className="py-2 text-right font-medium">{t("Period Dr")}</th><th className="py-2 text-right font-medium">{t("Period Cr")}</th>
              <th className="py-2 text-right font-medium">{t("Closing Dr")}</th><th className="py-2 text-right font-medium">{t("Closing Cr")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-ink-100">
            {d.rows.map((r) => (
              <tr key={r.account_id}>
                <td className="py-1.5"><Link href={`/admin/accounting/accounts/${r.account_id}?date_from=${range.date_from}&date_to=${range.date_to}`} className="hover:underline">{r.code} {r.name}</Link></td>
                {(["opening_debit", "opening_credit", "period_debit", "period_credit", "debit", "credit"] as const).map((k) => <td key={k} className="num py-1.5 text-right">{r[k] ? money(r[k]) : ""}</td>)}
              </tr>
            ))}
          </tbody>
          <tfoot className="border-t-2 border-ink-400 font-semibold">
            <tr>
              <td className="py-2">{t("Totals")}</td>
              {(["opening_debit", "opening_credit", "period_debit", "period_credit", "debit", "credit"] as const).map((k) => <td key={k} className="num py-2 text-right">{money(d.totals[k])}</td>)}
            </tr>
          </tfoot>
        </table>
      </div>
    </>
  );
}

function VatReturn({ range }: { range: Range }) {
  const t = useT();
  const q = useApi<ApiResponse<Vat>>("admin/accounting/reports/vat", range);
  const d = q.data?.data;
  if (!d) return <Spinner variant="page" />;
  return (
    <table className="w-full max-w-xl text-sm">
      <tbody>
        <Row label={t("Taxable sales (before VAT)")} value={d.taxable_sales} />
        <Row label={t("VAT charged on sales (output)")} value={d.output_vat} />
        <Row label={t("VAT paid on purchases (input)")} value={d.input_vat} />
        <Row label={d.net_vat_payable >= 0 ? t("VAT to pay to TRA") : t("VAT to reclaim")} value={d.net_vat_payable} strong />
      </tbody>
    </table>
  );
}

function Ageing({ kind, asOf }: { kind: "receivables" | "payables"; asOf: string }) {
  const t = useT();
  const q = useApi<ApiResponse<Aged>>(`admin/accounting/reports/aged-${kind}`, { as_of: asOf });
  const d = q.data?.data;
  if (!d) return <Spinner variant="page" />;
  const cols = [["current", "Not yet due"], ["1_30", "1–30 days"], ["31_60", "31–60 days"], ["61_90", "61–90 days"], ["over_90", "Over 90 days"], ["total", "Total"]] as const;
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="text-xs text-ink-500"><tr className="border-b border-ink-200"><th className="py-2 text-left font-medium">{kind === "receivables" ? t("Customer") : t("Supplier")}</th>{cols.map(([k, l]) => <th key={k} className="py-2 text-right font-medium">{t(l)}</th>)}</tr></thead>
        <tbody className="divide-y divide-ink-100">
          {d.rows.map((r, i) => (
            <tr key={`${r.party_id}-${i}`}>
              <td className="py-1.5">{r.party_id && kind === "receivables" ? <Link href={`/admin/customers/${r.party_id}?tab=finance`} className="hover:underline">{r.name}</Link> : r.name}<span className="block text-xs text-ink-500">{r.phone}</span></td>
              {cols.map(([k]) => <td key={k} className={cn("num py-1.5 text-right", k === "over_90" && r[k] > 0 && "text-ember-600", k === "total" && "font-semibold")}>{r[k] ? money(r[k]) : ""}</td>)}
            </tr>
          ))}
          {!d.rows.length && <tr><td colSpan={7} className="py-8 text-center text-ink-500">{t("Nothing outstanding.")}</td></tr>}
        </tbody>
        <tfoot className="border-t-2 border-ink-400 font-semibold"><tr><td className="py-2">{t("Totals")}</td>{cols.map(([k]) => <td key={k} className="num py-2 text-right">{money(d.totals[k])}</td>)}</tr></tfoot>
      </table>
    </div>
  );
}

export default function StatementsPage() {
  const t = useT();
  const { settings } = usePublicSettings();
  const [tab, setTab] = useState<Tab>("pnl");
  const [range, setRange] = useState<Range>({ date_from: monthStart(), date_to: today() });
  useUrlParam("tab", (v) => setTab(v as Tab));
  const pointInTime = tab === "balance" || tab === "receivables" || tab === "payables";
  const titles: Record<Tab, string> = { pnl: "Profit and loss", balance: "Balance sheet", cash: "Cash flow", trial: "Trial balance", vat: "VAT return", receivables: "Aged receivables (debtors)", payables: "Aged payables (creditors)" };

  return (
    <Guard perm="accounting.statements">
      <PageHeader title={t("Financial statements")} description={t("Straight from the double-entry books. Every sale, purchase, expense, payroll and savings movement is posted automatically.")}
        actions={<Button variant="secondary" icon={<Printer className="size-4" />} onClick={() => window.print()}>{t("Print")}</Button>} />
      <div className="no-print">
        <Tabs<Tab> value={tab} onChange={setTab} tabs={(Object.keys(titles) as Tab[]).map((k) => ({ value: k, label: t(titles[k]) }))} />
        <div className="my-4"><DateRange value={range} onChange={setRange} single={pointInTime} /></div>
      </div>
      <Panel>
        <header className="mb-4 border-b border-ink-100 pb-3">
          <p className="text-sm text-ink-500">{settings?.business_name}</p>
          <h2 className="text-lg font-semibold text-ink-950">{t(titles[tab])}</h2>
          <p className="text-sm text-ink-500">{pointInTime ? t("As of {date}", { date: date(range.date_to) }) : t("{from} to {to}", { from: date(range.date_from), to: date(range.date_to) })}</p>
        </header>
        {tab === "pnl" && <ProfitAndLoss range={range} />}
        {tab === "balance" && <BalanceSheet asOf={range.date_to} />}
        {tab === "cash" && <CashFlowView range={range} />}
        {tab === "trial" && <TrialBalance range={range} />}
        {tab === "vat" && <VatReturn range={range} />}
        {(tab === "receivables" || tab === "payables") && <Ageing kind={tab} asOf={range.date_to} />}
      </Panel>
    </Guard>
  );
}
