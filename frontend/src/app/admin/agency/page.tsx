"use client";

import Link from "next/link";
import { useState } from "react";
import { AlertTriangle, Moon, Settings, Sun } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, monthStart, today, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, EmptyState, ErrorState, Field, Input, PageHeader, Panel, Spinner, Stat, Textarea } from "@/components/ui";
import { AgencyNav, ProviderDot } from "@/features/agency/AgencyNav";
import { DaySummary, Difference } from "@/features/agency/DaySummary";
import type { AgencyAccount, AgencyDay, TodayPayload } from "@/features/agency/types";

type Summary = { latest_total: number; shortage: number; excess: number; commissions: number; days: number };

/**
 * Uwakala today: in the morning count cash and every float and open the day;
 * at night count again and close. The totals and the difference update as you type.
 */
export default function AgencyTodayPage() {
  const t = useT();
  const { can } = useAuth();
  const [day, setDay] = useState(today());
  const q = useApi<ApiResponse<TodayPayload>>("admin/agency/today", { date: day }, { keepPrevious: false });
  const summary = useApi<ApiResponse<Summary>>("admin/agency/summary", { date_from: monthStart(), date_to: today() });
  const s = summary.data?.data;
  const d = q.data?.data;

  return (
    <Guard perm="agency.view">
      <PageHeader
        title={t("Uwakala")}
        description={t("Count the cash and every float in the morning and again at night. The system adds them up and shows any shortage or excess.")}
        actions={<Input type="date" max={today()} value={day} onChange={(e) => setDay(e.target.value || today())} className="w-auto" aria-label={t("Date")} />}
      />
      <AgencyNav />
      {s && (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label={t("Total now (cash + floats)")} value={money(s.latest_total)} tone="brand" />
          <Stat label={t("Shortages this month")} value={money(s.shortage)} tone={s.shortage < 0 ? "ember" : "default"} />
          <Stat label={t("Excesses this month")} value={money(s.excess)} />
          <Stat label={t("Commissions this month")} value={money(s.commissions)} sub={t("{count} days recorded", { count: s.days })} />
        </div>
      )}
      {q.isLoading && <Spinner />}
      {q.error && <ErrorState message={q.error.message} onRetry={() => q.refetch()} />}
      {d && !d.accounts.length && (
        <Panel>
          <EmptyState icon={<Settings className="size-9" />} title={t("No agency lines yet")} message={t("Add the lines you work with (M-Pesa, Mixx by Yas, Airtel Money, HaloPesa, NMB, CRDB, NBC ...) first.")}
            action={can("agency.manage") ? <Link href="/admin/agency/accounts"><Button>{t("Add lines")}</Button></Link> : undefined} />
        </Panel>
      )}
      {d && d.unclosed && (
        <p className="mb-4 flex items-center gap-2 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-800 ring-1 ring-saffron-100">
          <AlertTriangle className="size-4 shrink-0" />
          {t("The day of {date} was never closed. Close it first so the figures join up.", { date: date(d.unclosed.date) })}
          <button type="button" className="font-semibold underline" onClick={() => setDay(d.unclosed!.date)}>{t("Open that day")}</button>
        </p>
      )}
      {d && d.accounts.length > 0 && !d.day && (can("agency.record") ? <OpeningForm data={d} onDone={() => { q.refetch(); summary.refetch(); }} /> : <Panel><EmptyState title={t("Not opened yet")} message={t("The day has not been opened.")} /></Panel>)}
      {d?.day && d.day.status === "open" && (
        <>
          <DaySummary day={d.day} />
          {can("agency.record") && <ClosingForm day={d.day} accounts={d.accounts} onDone={() => { q.refetch(); summary.refetch(); }} />}
        </>
      )}
      {d?.day && d.day.status === "closed" && <DaySummary day={d.day} />}
    </Guard>
  );
}

function AmountRow({ account, value, onChange, hint }: { account: AgencyAccount; value: string; onChange: (v: string) => void; hint?: string }) {
  return (
    <div className="flex items-center gap-3 border-b border-ink-100 py-2 last:border-0">
      <ProviderDot provider={account.provider} />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-ink-900">{account.name}</p>
        <p className="truncate text-xs text-ink-500">{[account.agent_number, hint].filter(Boolean).join(" · ")}</p>
      </div>
      <Input type="number" inputMode="decimal" min={0} step="any" value={value} onChange={(e) => onChange(e.target.value)} className="num h-10 w-40 text-right" aria-label={account.name} />
    </div>
  );
}

function OpeningForm({ data, onDone }: { data: TodayPayload; onDone: () => void }) {
  const t = useT();
  const sug = data.suggestion;
  const [cash, setCash] = useState(sug ? String(sug.cash) : "");
  const [floats, setFloats] = useState<Record<number, string>>(() => Object.fromEntries(data.accounts.map((a) => [a.id, sug?.floats[a.id] !== undefined ? String(sug.floats[a.id]) : ""])));
  const [note, setNote] = useState("");
  const floatTotal = data.accounts.reduce((sum, a) => sum + toNumber(floats[a.id]), 0);
  const open = useAction(() => api.post("admin/agency/days", {
    date: data.date, cash: toNumber(cash), note: note || null,
    balances: data.accounts.map((a) => ({ account_id: a.id, amount: toNumber(floats[a.id]) })),
  }), { invalidate: [["admin/agency/days"]], onSuccess: onDone });
  const e = fieldErrors(open.error);

  return (
    <Panel title={<span className="flex items-center gap-2"><Sun className="size-5 text-saffron-600" /> {t("Morning opening: {date}", { date: date(data.date) })}</span>}>
      {sug && <p className="mb-3 rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-800">{t("Filled in from the closing of {date}. Change any figure that is different this morning.", { date: date(sug.from_date) })}</p>}
      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div>
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-500">{t("Float on each line")}</p>
          {data.accounts.map((a) => <AmountRow key={a.id} account={a} value={floats[a.id] ?? ""} onChange={(v) => setFloats({ ...floats, [a.id]: v })} />)}
          <div className="mt-4">
            <Field label={t("Cash in hand")} required error={e.cash}>{(p) => <Input {...p} type="number" inputMode="decimal" min={0} step="any" value={cash} onChange={(ev) => setCash(ev.target.value)} className="num text-right text-lg" />}</Field>
          </div>
          <Field label={t("Note (optional)")} className="mt-3">{(p) => <Textarea {...p} rows={2} value={note} onChange={(ev) => setNote(ev.target.value)} />}</Field>
        </div>
        <Totals rows={[[t("Floats"), floatTotal], [t("Cash"), toNumber(cash)]]} total={floatTotal + toNumber(cash)} label={t("Total at opening")}>
          <Button size="lg" className="mt-4 w-full" icon={<Sun className="size-5" />} loading={open.isPending} disabled={cash === ""} onClick={() => open.mutate(undefined)}>{t("Open the day")}</Button>
        </Totals>
      </div>
    </Panel>
  );
}

function ClosingForm({ day, accounts, onDone }: { day: AgencyDay; accounts: AgencyAccount[]; onDone: () => void }) {
  const t = useT();
  // Lines opened this morning, plus any line added since.
  const lines = [...day.balances.map((b) => b.account!).filter(Boolean), ...accounts.filter((a) => !day.balances.some((b) => b.agency_account_id === a.id))];
  const [cash, setCash] = useState("");
  const [floats, setFloats] = useState<Record<number, string>>({});
  const [added, setAdded] = useState("");
  const [withdrawn, setWithdrawn] = useState("");
  const [note, setNote] = useState("");
  const floatTotal = lines.reduce((sum, a) => sum + toNumber(floats[a.id]), 0);
  const closing = floatTotal + toNumber(cash);
  const expected = day.opening_total + toNumber(added) - toNumber(withdrawn);
  const complete = cash !== "" && lines.every((a) => (floats[a.id] ?? "") !== "");
  const diff = closing - expected;
  const close = useAction(() => api.post(`admin/agency/days/${day.id}/close`, {
    cash: toNumber(cash), capital_added: toNumber(added), capital_withdrawn: toNumber(withdrawn), note: note || null,
    balances: lines.map((a) => ({ account_id: a.id, amount: toNumber(floats[a.id]) })),
  }), { invalidate: [["admin/agency/days"]], onSuccess: onDone });
  const opening = (id: number) => day.balances.find((b) => b.agency_account_id === id);

  return (
    <Panel className="mt-5" title={<span className="flex items-center gap-2"><Moon className="size-5 text-night-700" /> {t("Evening closing")}</span>}>
      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div>
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-500">{t("Float on each line now")}</p>
          {lines.map((a) => <AmountRow key={a.id} account={a} value={floats[a.id] ?? ""} onChange={(v) => setFloats({ ...floats, [a.id]: v })}
            hint={opening(a.id) ? t("morning {amount}", { amount: money(opening(a.id)!.opening_float) }) : t("added today")} />)}
          <div className="mt-4 grid gap-3 sm:grid-cols-3">
            <Field label={t("Cash in hand")} required>{(p) => <Input {...p} type="number" inputMode="decimal" min={0} step="any" value={cash} onChange={(ev) => setCash(ev.target.value)} className="num text-right" />}</Field>
            <Field label={t("Capital added today")} hint={t("Money the owner put in")}>{(p) => <Input {...p} type="number" min={0} step="any" value={added} onChange={(ev) => setAdded(ev.target.value)} className="num text-right" />}</Field>
            <Field label={t("Capital taken out today")} hint={t("Money taken out of the business")}>{(p) => <Input {...p} type="number" min={0} step="any" value={withdrawn} onChange={(ev) => setWithdrawn(ev.target.value)} className="num text-right" />}</Field>
          </div>
          <Field label={t("Note (optional)")} className="mt-3">{(p) => <Textarea {...p} rows={2} value={note} onChange={(ev) => setNote(ev.target.value)} />}</Field>
        </div>
        <Totals rows={[[t("Floats"), floatTotal], [t("Cash"), toNumber(cash)], [t("Expected (morning ± capital)"), expected]]} total={closing} label={t("Total at closing")}>
          {complete && <Difference value={diff} />}
          <Button size="lg" className="mt-4 w-full" icon={<Moon className="size-5" />} loading={close.isPending} disabled={!complete} onClick={() => close.mutate(undefined)}>{t("Close the day")}</Button>
          {!complete && <p className="mt-2 text-center text-xs text-ink-500">{t("Enter the cash and the float of every line.")}</p>}
        </Totals>
      </div>
    </Panel>
  );
}

function Totals({ rows, total, label, children }: { rows: Array<[string, number]>; total: number; label: string; children?: React.ReactNode }) {
  return (
    <div className="rounded-lg bg-ink-50 p-4 ring-1 ring-ink-100 lg:sticky lg:top-20 lg:self-start">
      <dl className="space-y-1.5 text-sm">
        {rows.map(([k, v]) => <div key={k} className="flex justify-between"><dt className="text-ink-600">{k}</dt><dd className="num">{money(v)}</dd></div>)}
        <div className="flex items-end justify-between border-t border-ink-200 pt-2"><dt className="font-semibold">{label}</dt><dd className="num text-2xl font-semibold tracking-tight">{money(total)}</dd></div>
      </dl>
      {children}
    </div>
  );
}
