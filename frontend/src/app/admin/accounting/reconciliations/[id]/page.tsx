"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use } from "react";
import { ArrowLeft, CheckCircle2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { cn, date, money, toNumber } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, PageHeader, Panel, Spinner, Stat } from "@/components/ui";
import type { Reconciliation } from "@/features/accounting/types";

type Line = { id: number; entry_date: string; debit: string; credit: string; memo: string | null; reconciliation_id: number | null; entry?: { number: string; description: string; reference: string | null } };

export default function ReconcilePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useT();
  const router = useRouter();
  const q = useApi<ApiResponse<{ reconciliation: Reconciliation; lines: Line[] }>>(`admin/accounting/reconciliations/${id}`);
  const inv = [[`admin/accounting/reconciliations/${id}`], ["admin/accounting/reconciliations"]];
  const mark = useAction((v: { ids: number[]; cleared: boolean }) => api.post(`admin/accounting/reconciliations/${id}/lines`, { line_ids: v.ids, cleared: v.cleared }), { invalidate: inv, silent: true });
  const complete = useAction(() => api.post(`admin/accounting/reconciliations/${id}/complete`), { invalidate: inv });
  const discard = useAction(() => api.del(`admin/accounting/reconciliations/${id}`), { invalidate: inv, onSuccess: () => router.replace("/admin/accounting/reconciliations") });
  const d = q.data?.data;
  if (!d) return <Guard perm="accounting.reconcile"><Spinner variant="page" /></Guard>;
  const r = d.reconciliation;
  const draft = r.status === "draft";
  const diff = toNumber(r.difference);

  return (
    <Guard perm="accounting.reconcile">
      <PageHeader
        back={<Link href="/admin/accounting/reconciliations" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Bank reconciliation")}</Link>}
        title={`${r.account?.code ?? ""} ${r.account?.name ?? ""}`}
        description={t("Statement of {date}. Tick every entry that appears on the statement.", { date: date(r.statement_date) })}
        actions={draft && <><Button variant="ghost" loading={discard.isPending} onClick={() => confirm(t("Discard this reconciliation?")) && discard.mutate(undefined)}>{t("Discard")}</Button><Button icon={<CheckCircle2 className="size-4" />} disabled={Math.abs(diff) >= 0.005} loading={complete.isPending} onClick={() => complete.mutate(undefined)}>{t("Finish")}</Button></>}
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={t("Statement balance")} value={money(r.statement_balance)} />
        <Stat label={t("Balance in the books")} value={money(r.book_balance)} />
        <Stat label={t("Ticked (cleared)")} value={money(r.cleared_balance)} />
        <Stat label={t("Difference")} value={money(diff)} tone={Math.abs(diff) < 0.005 ? "brand" : "ember"} sub={Math.abs(diff) < 0.005 ? t("Ready to finish") : t("Record missing bank charges as a journal, then tick them.")} />
      </div>
      <Panel padded={false}>
        <table className="w-full text-sm">
          <thead className="bg-ink-50 text-left text-xs text-ink-500"><tr><th className="w-10 px-3 py-2" /><th className="px-3 py-2 font-medium">{t("Date")}</th><th className="px-3 py-2 font-medium">{t("Entry")}</th><th className="px-3 py-2 text-right font-medium">{t("Money in")}</th><th className="px-3 py-2 text-right font-medium">{t("Money out")}</th></tr></thead>
          <tbody className="divide-y divide-ink-100">
            {d.lines.map((l) => {
              const ticked = l.reconciliation_id !== null;
              return (
                <tr key={l.id} className={cn(ticked && "bg-brand-50/50")}>
                  <td className="px-3 py-2"><input type="checkbox" aria-label={t("Cleared")} className="size-4 accent-brand-600" disabled={!draft || mark.isPending} checked={ticked} onChange={(e) => mark.mutate({ ids: [l.id], cleared: e.target.checked })} /></td>
                  <td className="whitespace-nowrap px-3 py-2">{date(l.entry_date)}</td>
                  <td className="px-3 py-2">{l.entry?.description}<span className="block text-xs text-ink-500">{l.entry?.number}{l.entry?.reference ? ` · ${l.entry.reference}` : ""}</span></td>
                  <td className="num px-3 py-2 text-right">{toNumber(l.debit) ? money(l.debit) : ""}</td>
                  <td className="num px-3 py-2 text-right">{toNumber(l.credit) ? money(l.credit) : ""}</td>
                </tr>
              );
            })}
            {!d.lines.length && <tr><td colSpan={5} className="px-3 py-8 text-center text-ink-500">{t("No entries up to the statement date.")}</td></tr>}
          </tbody>
        </table>
      </Panel>
    </Guard>
  );
}
