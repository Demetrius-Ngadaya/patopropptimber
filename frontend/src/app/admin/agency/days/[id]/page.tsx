"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, Pencil } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, ErrorState, Field, Input, Modal, PageHeader, Spinner, Textarea } from "@/components/ui";
import { AgencyNav } from "@/features/agency/AgencyNav";
import { DaySummary } from "@/features/agency/DaySummary";
import type { AgencyDay } from "@/features/agency/types";

export default function AgencyDayPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const { can } = useAuth();
  const q = useApi<ApiResponse<AgencyDay>>(`admin/agency/days/${id}`, undefined, { keepPrevious: false });
  const [editing, setEditing] = useState(false);
  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Not found.")} />;
  const day = q.data.data;

  return (
    <Guard perm="agency.view">
      <PageHeader
        back={<Link href="/admin/agency/days" className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Uwakala days")}</Link>}
        title={t("Uwakala {date}", { date: date(day.business_date) })}
        description={day.branch?.name}
        actions={can("agency.manage") && <Button variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>{t("Correct figures")}</Button>}
      />
      <AgencyNav />
      <DaySummary day={day} />
      {editing && <CorrectDialog day={day} onClose={() => setEditing(false)} onDone={() => { setEditing(false); q.refetch(); }} />}
    </Guard>
  );
}

function CorrectDialog({ day, onClose, onDone }: { day: AgencyDay; onClose: () => void; onDone: () => void }) {
  const t = useT();
  const closed = day.status === "closed";
  const [f, setF] = useState({
    opening_cash: String(toNumber(day.opening_cash)), closing_cash: closed ? String(toNumber(day.closing_cash)) : "",
    capital_added: String(toNumber(day.capital_added)), capital_withdrawn: String(toNumber(day.capital_withdrawn)), reason: "",
  });
  const [open, setOpen] = useState<Record<number, string>>(Object.fromEntries(day.balances.map((b) => [b.agency_account_id, String(toNumber(b.opening_float))])));
  const [close, setClose] = useState<Record<number, string>>(Object.fromEntries(day.balances.map((b) => [b.agency_account_id, b.closing_float === null ? "" : String(toNumber(b.closing_float))])));
  const save = useAction(() => api.put(`admin/agency/days/${day.id}`, {
    reason: f.reason,
    opening_cash: toNumber(f.opening_cash),
    opening_balances: day.balances.map((b) => ({ account_id: b.agency_account_id, amount: toNumber(open[b.agency_account_id]) })),
    ...(closed ? {
      closing_cash: toNumber(f.closing_cash), capital_added: toNumber(f.capital_added), capital_withdrawn: toNumber(f.capital_withdrawn),
      closing_balances: day.balances.map((b) => ({ account_id: b.agency_account_id, amount: toNumber(close[b.agency_account_id]) })),
    } : {}),
  }), { onSuccess: onDone });
  const e = fieldErrors(save.error);
  const num = (value: string, set: (v: string) => void, label: string) => <Input type="number" min={0} step="any" value={value} onChange={(ev) => set(ev.target.value)} className="num h-9 text-right" aria-label={label} />;

  return (
    <Modal open onClose={onClose} size="lg" title={t("Correct the figures")} description={t("The change and its reason are kept in the audit log.")}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={f.reason.trim().length < 3} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></>}>
      <table className="w-full text-sm">
        <thead><tr className="text-left text-xs text-ink-500"><th className="py-1">{t("Line")}</th><th className="py-1">{t("Morning")}</th>{closed && <th className="py-1">{t("Evening")}</th>}</tr></thead>
        <tbody>
          {day.balances.map((b) => (
            <tr key={b.id}>
              <td className="py-1 pr-2">{b.account?.name}</td>
              <td className="py-1 pr-2">{num(open[b.agency_account_id] ?? "", (v) => setOpen({ ...open, [b.agency_account_id]: v }), `${b.account?.name} ${t("Morning")}`)}</td>
              {closed && <td className="py-1">{num(close[b.agency_account_id] ?? "", (v) => setClose({ ...close, [b.agency_account_id]: v }), `${b.account?.name} ${t("Evening")}`)}</td>}
            </tr>
          ))}
          <tr>
            <td className="py-1 pr-2 font-medium">{t("Cash in hand")}</td>
            <td className="py-1 pr-2">{num(f.opening_cash, (v) => setF({ ...f, opening_cash: v }), t("Cash at opening"))}</td>
            {closed && <td className="py-1">{num(f.closing_cash, (v) => setF({ ...f, closing_cash: v }), t("Cash at closing"))}</td>}
          </tr>
        </tbody>
      </table>
      {closed && (
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <Field label={t("Capital added")}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.capital_added} onChange={(ev) => setF({ ...f, capital_added: ev.target.value })} />}</Field>
          <Field label={t("Capital taken out")}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.capital_withdrawn} onChange={(ev) => setF({ ...f, capital_withdrawn: ev.target.value })} />}</Field>
        </div>
      )}
      <Field label={t("Reason for the correction")} required error={e.reason} className="mt-3">{(p) => <Textarea {...p} rows={2} value={f.reason} onChange={(ev) => setF({ ...f, reason: ev.target.value })} />}</Field>
    </Modal>
  );
}
