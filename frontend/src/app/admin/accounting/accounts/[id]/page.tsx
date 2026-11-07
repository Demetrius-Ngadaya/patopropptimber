"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, Printer } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { date, money, monthStart, today } from "@/lib/format";
import { useApi, useUrlParam } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, PageHeader, Panel, Spinner, Stat } from "@/components/ui";
import { DateRange, type Range } from "@/features/accounting/components/DateRange";
import { eventLabel } from "@/features/accounting/types";

type Ledger = {
  account: { id: number; code: string; name: string; type: string; sub_type: string | null };
  opening_balance: number; closing_balance: number; total_debit: number; total_credit: number;
  lines: Array<{ date: string; journal_id: number; number: string; description: string; reference: string | null; event: string | null; party: string | null; debit: number; credit: number; balance: number }>;
};

export default function AccountLedgerPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useT();
  const [range, setRange] = useState<Range>({ date_from: monthStart(), date_to: today() });
  useUrlParam("date_from", (v) => setRange((r) => ({ ...r, date_from: v })));
  useUrlParam("date_to", (v) => setRange((r) => ({ ...r, date_to: v })));
  const q = useApi<ApiResponse<Ledger>>(`admin/accounting/accounts/${id}/ledger`, range);
  const d = q.data?.data;

  return (
    <Guard perm="accounting.view">
      <PageHeader
        back={<Link href="/admin/accounting/accounts" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Chart of accounts")}</Link>}
        title={d ? `${d.account.code} ${d.account.name}` : t("General ledger")}
        description={t("Every posting to this account, with the running balance.")}
        actions={<Button variant="secondary" icon={<Printer className="size-4" />} onClick={() => window.print()}>{t("Print")}</Button>}
      />
      <div className="no-print mb-4"><DateRange value={range} onChange={setRange} /></div>
      {!d ? <Spinner /> : (
        <>
          <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label={t("Opening balance")} value={money(d.opening_balance)} />
            <Stat label={t("Debits")} value={money(d.total_debit)} />
            <Stat label={t("Credits")} value={money(d.total_credit)} />
            <Stat label={t("Closing balance")} value={money(d.closing_balance)} tone="brand" />
          </div>
          <Panel padded={false}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-ink-50 text-left text-xs text-ink-500">
                  <tr><th className="px-3 py-2 font-medium">{t("Date")}</th><th className="px-3 py-2 font-medium">{t("Entry")}</th><th className="px-3 py-2 font-medium">{t("Description")}</th><th className="px-3 py-2 text-right font-medium">{t("Debit")}</th><th className="px-3 py-2 text-right font-medium">{t("Credit")}</th><th className="px-3 py-2 text-right font-medium">{t("Balance")}</th></tr>
                </thead>
                <tbody className="divide-y divide-ink-100">
                  <tr className="bg-ink-50/40"><td className="px-3 py-2" colSpan={5}>{t("Opening balance")}</td><td className="num px-3 py-2 text-right font-semibold">{money(d.opening_balance)}</td></tr>
                  {d.lines.map((l, i) => (
                    <tr key={`${l.journal_id}-${i}`}>
                      <td className="whitespace-nowrap px-3 py-2">{date(l.date)}</td>
                      <td className="px-3 py-2"><Link href={`/admin/accounting/journals?open=${l.journal_id}`} className="text-brand-700 hover:underline">{l.number}</Link><span className="block text-xs text-ink-500">{t(eventLabel(l.event))}</span></td>
                      <td className="px-3 py-2">{l.description}{l.party && <span className="block text-xs text-ink-500">{l.party}</span>}</td>
                      <td className="num px-3 py-2 text-right">{l.debit ? money(l.debit) : ""}</td>
                      <td className="num px-3 py-2 text-right">{l.credit ? money(l.credit) : ""}</td>
                      <td className="num px-3 py-2 text-right font-medium">{money(l.balance)}</td>
                    </tr>
                  ))}
                  {!d.lines.length && <tr><td colSpan={6} className="px-3 py-8 text-center text-ink-500">{t("No postings in this period.")}</td></tr>}
                </tbody>
              </table>
            </div>
          </Panel>
        </>
      )}
    </Guard>
  );
}
