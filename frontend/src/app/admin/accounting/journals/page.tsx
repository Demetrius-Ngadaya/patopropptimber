"use client";

import Link from "next/link";
import { useState } from "react";
import { Plus, Trash2, Undo2, Zap } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, date, dateTime, money, toNumber, today } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced, useUrlParam } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, Spinner, Tabs, Textarea } from "@/components/ui";
import { AccountSelect } from "@/features/accounting/components/AccountSelect";
import { eventLabel, type JournalEntry } from "@/features/accounting/types";

type Line = { account_id: number | ""; debit: string; credit: string; memo: string };
const emptyLine = (): Line => ({ account_id: "", debit: "", credit: "", memo: "" });

function ManualJournal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const [head, setHead] = useState({ entry_date: today(), description: "", reference: "" });
  const [lines, setLines] = useState<Line[]>([emptyLine(), emptyLine()]);
  const debits = lines.reduce((s, l) => s + toNumber(l.debit), 0);
  const credits = lines.reduce((s, l) => s + toNumber(l.credit), 0);
  const diff = Math.round((debits - credits) * 100) / 100;
  const save = useAction(
    () => api.post("admin/accounting/journals", { ...head, reference: head.reference || undefined, lines: lines.filter((l) => l.account_id && (toNumber(l.debit) || toNumber(l.credit))).map((l) => ({ account_id: l.account_id, debit: toNumber(l.debit) || undefined, credit: toNumber(l.credit) || undefined, memo: l.memo || undefined })) }),
    { invalidate: [["admin/accounting/journals"], ["admin/accounting/accounts"]], onSuccess: () => { onClose(); setLines([emptyLine(), emptyLine()]); setHead({ entry_date: today(), description: "", reference: "" }); } },
  );
  const e = fieldErrors(save.error);
  const setLine = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  return (
    <Modal open={open} onClose={onClose} size="xl" title={t("New journal entry")} description={t("Debits must equal credits. Accounts kept automatically (customers, suppliers, stock, savings) cannot be used here.")}
      footer={
        <>
          <span className={cn("mr-auto text-sm font-medium", diff === 0 && debits > 0 ? "text-brand-700" : "text-ember-600")}>
            {diff === 0 ? (debits > 0 ? t("Balanced: {amount}", { amount: money(debits) }) : "") : t("Difference: {amount}", { amount: money(Math.abs(diff)) })}
          </span>
          <Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button>
          <Button loading={save.isPending} disabled={diff !== 0 || debits <= 0 || !head.description.trim()} onClick={() => save.mutate(undefined)}>{t("Post journal")}</Button>
        </>
      }>
      <div className="grid gap-3 sm:grid-cols-[160px_1fr_200px]">
        <Field label={t("Date")} required error={e.entry_date}>{(p) => <Input {...p} type="date" max={today()} value={head.entry_date} onChange={(ev) => setHead({ ...head, entry_date: ev.target.value })} />}</Field>
        <Field label={t("Description")} required error={e.description}>{(p) => <Input {...p} value={head.description} onChange={(ev) => setHead({ ...head, description: ev.target.value })} />}</Field>
        <Field label={t("Reference")}>{(p) => <Input {...p} value={head.reference} onChange={(ev) => setHead({ ...head, reference: ev.target.value })} />}</Field>
      </div>
      <div className="mt-4 overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="text-left text-xs text-ink-500"><tr><th className="pb-1 font-medium">{t("Account")}</th><th className="w-36 pb-1 text-right font-medium">{t("Debit")}</th><th className="w-36 pb-1 text-right font-medium">{t("Credit")}</th><th className="pb-1 font-medium">{t("Memo")}</th><th /></tr></thead>
          <tbody>
            {lines.map((l, i) => (
              <tr key={i}>
                <td className="py-1 pr-2"><AccountSelect aria-label={t("Account")} manualOnly value={l.account_id} onChange={(v) => setLine(i, { account_id: v })} /></td>
                <td className="py-1 pr-2"><Input aria-label={t("Debit")} type="number" min={0} step="any" className="num text-right" value={l.debit} onChange={(ev) => setLine(i, { debit: ev.target.value, credit: ev.target.value ? "" : l.credit })} /></td>
                <td className="py-1 pr-2"><Input aria-label={t("Credit")} type="number" min={0} step="any" className="num text-right" value={l.credit} onChange={(ev) => setLine(i, { credit: ev.target.value, debit: ev.target.value ? "" : l.debit })} /></td>
                <td className="py-1 pr-2"><Input aria-label={t("Memo")} value={l.memo} onChange={(ev) => setLine(i, { memo: ev.target.value })} /></td>
                <td className="py-1"><Button variant="ghost" size="sm" aria-label={t("Remove line")} disabled={lines.length <= 2} onClick={() => setLines((ls) => ls.filter((_, idx) => idx !== i))}><Trash2 className="size-4" /></Button></td>
              </tr>
            ))}
          </tbody>
          <tfoot><tr className="border-t border-ink-200 font-semibold"><td className="py-2">{t("Totals")}</td><td className="num py-2 pr-2 text-right">{money(debits)}</td><td className="num py-2 pr-2 text-right">{money(credits)}</td><td colSpan={2} /></tr></tfoot>
        </table>
      </div>
      <Button className="mt-2" size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setLines((ls) => [...ls, emptyLine()])}>{t("Add line")}</Button>
    </Modal>
  );
}

const QUICK: Array<{ value: string; label: string; hint: string }> = [
  { value: "transfer", label: "Move money between accounts", hint: "e.g. cash banked, or mobile money withdrawn to cash." },
  { value: "capital", label: "Owner puts money in (capital)", hint: "Money the owner invests in the business." },
  { value: "drawings", label: "Owner takes money out (drawings)", hint: "Money the owner takes for personal use." },
  { value: "other_income", label: "Other income", hint: "Commission, rent received, interest and similar." },
  { value: "loan_received", label: "Loan received", hint: "A bank or other loan paid into the business." },
  { value: "loan_repayment", label: "Loan repayment", hint: "Paying back part of a loan." },
];

function QuickEntry({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const [f, setF] = useState({ type: "transfer", amount: "", account_id: "" as number | "", from_account_id: "" as number | "", to_account_id: "" as number | "", entry_date: today(), description: "", reference: "" });
  const save = useAction(() => api.post("admin/accounting/journals/quick", { ...f, amount: Number(f.amount), account_id: f.account_id || undefined, from_account_id: f.from_account_id || undefined, to_account_id: f.to_account_id || undefined, description: f.description || undefined, reference: f.reference || undefined }),
    { invalidate: [["admin/accounting/journals"], ["admin/accounting/accounts"]], onSuccess: () => { onClose(); setF((x) => ({ ...x, amount: "", description: "", reference: "" })); } });
  const e = fieldErrors(save.error);
  const quick = QUICK.find((q) => q.value === f.type)!;
  const isTransfer = f.type === "transfer";

  return (
    <Modal open={open} onClose={onClose} title={t("Quick entry")} description={t("Common money movements without writing a full journal.")}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={!Number(f.amount) || (isTransfer ? !f.from_account_id || !f.to_account_id : !f.account_id)} onClick={() => save.mutate(undefined)}>{t("Record")}</Button></>}>
      <div className="space-y-4">
        <Field label={t("What happened?")} hint={t(quick.hint)}>{(p) => <Select {...p} value={f.type} onChange={(ev) => setF({ ...f, type: ev.target.value })}>{QUICK.map((q) => <option key={q.value} value={q.value}>{t(q.label)}</option>)}</Select>}</Field>
        {isTransfer ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t("From")} required error={e.from_account_id}>{(p) => <AccountSelect {...p} money value={f.from_account_id} onChange={(v) => setF({ ...f, from_account_id: v })} />}</Field>
            <Field label={t("To")} required error={e.to_account_id}>{(p) => <AccountSelect {...p} money value={f.to_account_id} onChange={(v) => setF({ ...f, to_account_id: v })} />}</Field>
          </div>
        ) : (
          <Field label={f.type === "drawings" || f.type === "loan_repayment" ? t("Paid from") : t("Received into")} required error={e.account_id}>{(p) => <AccountSelect {...p} money value={f.account_id} onChange={(v) => setF({ ...f, account_id: v })} />}</Field>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t("Amount")} required error={e.amount}>{(p) => <Input {...p} type="number" min={0} step="any" className="num text-right" value={f.amount} onChange={(ev) => setF({ ...f, amount: ev.target.value })} />}</Field>
          <Field label={t("Date")} required>{(p) => <Input {...p} type="date" max={today()} value={f.entry_date} onChange={(ev) => setF({ ...f, entry_date: ev.target.value })} />}</Field>
        </div>
        <Field label={t("Description (optional)")}>{(p) => <Input {...p} value={f.description} onChange={(ev) => setF({ ...f, description: ev.target.value })} />}</Field>
        <Field label={t("Reference")}>{(p) => <Input {...p} value={f.reference} onChange={(ev) => setF({ ...f, reference: ev.target.value })} />}</Field>
      </div>
    </Modal>
  );
}

function EntryDetail({ id, onClose }: { id: number; onClose: () => void }) {
  const t = useT();
  const { can } = useAuth();
  const [reversing, setReversing] = useState(false);
  const q = useApi<ApiResponse<JournalEntry>>(`admin/accounting/journals/${id}`);
  const reverse = useAction((reason: string) => api.post(`admin/accounting/journals/${id}/reverse`, { reason }), { invalidate: [["admin/accounting/journals"], ["admin/accounting/accounts"]], onSuccess: () => { setReversing(false); onClose(); } });
  const d = q.data?.data;
  return (
    <Modal open onClose={onClose} size="lg" title={d ? t("Journal {number}", { number: d.number }) : t("Journal")}
      footer={d && d.status === "posted" && !d.reversal_of_id && can("accounting.reverse") && (d.is_manual || can("accounting.close_year")) ? <Button variant="secondary" icon={<Undo2 className="size-4" />} onClick={() => setReversing(true)}>{t("Reverse")}</Button> : undefined}>
      {!d ? <Spinner /> : (
        <>
          <dl className="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt className="text-ink-500">{t("Date")}</dt><dd>{date(d.entry_date)}</dd></div>
            <div><dt className="text-ink-500">{t("Type")}</dt><dd>{t(eventLabel(d.event))}</dd></div>
            <div><dt className="text-ink-500">{t("Status")}</dt><dd>{d.status === "reversed" ? <Badge tone="red">{t("Reversed")}</Badge> : <Badge tone="green">{t("Posted")}</Badge>}</dd></div>
            <div><dt className="text-ink-500">{t("By")}</dt><dd>{d.creator?.name ?? t("System")}<span className="block text-xs text-ink-500">{dateTime(d.created_at)}</span></dd></div>
          </dl>
          <p className="mb-3 text-sm text-ink-800">{d.description}{d.reference && <span className="text-ink-500"> · {d.reference}</span>}</p>
          {(d.reversed_by || d.reversal_of) && <p className="mb-3 text-sm text-ember-700">{d.reversed_by ? t("Reversed by {number}", { number: d.reversed_by.number }) : t("Reverses {number}", { number: d.reversal_of!.number })}</p>}
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-ink-500"><tr><th className="pb-1 font-medium">{t("Account")}</th><th className="pb-1 text-right font-medium">{t("Debit")}</th><th className="pb-1 text-right font-medium">{t("Credit")}</th></tr></thead>
            <tbody className="divide-y divide-ink-100">
              {d.lines.map((l) => (
                <tr key={l.id}>
                  <td className="py-1.5"><Link href={`/admin/accounting/accounts/${l.account_id}`} className="hover:underline">{l.account?.code} {l.account?.name}</Link>{(l.memo || l.customer || l.supplier) && <span className="block text-xs text-ink-500">{[l.memo, l.customer?.name, l.supplier?.name].filter(Boolean).join(" · ")}</span>}</td>
                  <td className="num py-1.5 text-right">{toNumber(l.debit) ? money(l.debit) : ""}</td>
                  <td className="num py-1.5 text-right">{toNumber(l.credit) ? money(l.credit) : ""}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t border-ink-300 font-semibold"><tr><td className="py-2">{t("Totals")}</td><td className="num py-2 text-right">{money(d.total)}</td><td className="num py-2 text-right">{money(d.total)}</td></tr></tfoot>
          </table>
          <ConfirmDialog open={reversing} onClose={() => setReversing(false)} onConfirm={(reason) => reverse.mutate(reason)} requireReason loading={reverse.isPending}
            title={t("Reverse this journal?")} message={t("A mirror entry is posted so the balances return to what they were. Nothing is deleted.")} confirmLabel={t("Reverse journal")} />
        </>
      )}
    </Modal>
  );
}

export default function JournalsPage() {
  const t = useT();
  const { can } = useAuth();
  const [kind, setKind] = useState<"" | "1" | "0">("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [range, setRange] = useState({ date_from: "", date_to: "" });
  const [manual, setManual] = useState(false);
  const [quick, setQuick] = useState(false);
  const [openId, setOpenId] = useState<number | null>(null);
  useUrlParam("open", (v) => setOpenId(Number(v)));
  const q = useDebounced(search);
  const list = useApi<Paginated<JournalEntry>>("admin/accounting/journals", { manual: kind || undefined, search: q, page, ...range });

  return (
    <Guard perm="accounting.view">
      <PageHeader title={t("Journals")} description={t("Every entry in the books. Automatic entries come from sales, purchases, expenses, payroll and savings.")}
        actions={can("accounting.journal") && <><Button variant="secondary" icon={<Zap className="size-4" />} onClick={() => setQuick(true)}>{t("Quick entry")}</Button><Button icon={<Plus className="size-4" />} onClick={() => setManual(true)}>{t("New journal")}</Button></>} />
      <Panel padded={false}>
        <div className="px-3 pt-2"><Tabs value={kind} onChange={(v) => { setKind(v); setPage(1); }} tabs={[{ value: "", label: t("All") }, { value: "1", label: t("Manual") }, { value: "0", label: t("Automatic") }]} /></div>
        <div className="flex flex-wrap items-end gap-3 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Number, description or reference")} className="w-full max-w-xs" />
          <Field label={t("From")}>{(p) => <Input {...p} type="date" value={range.date_from} onChange={(e) => setRange({ ...range, date_from: e.target.value })} />}</Field>
          <Field label={t("To")}>{(p) => <Input {...p} type="date" value={range.date_to} onChange={(e) => setRange({ ...range, date_to: e.target.value })} />}</Field>
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => setOpenId(r.id)}
          columns={[
            { key: "entry_date", header: t("Date"), render: (r) => date(r.entry_date) },
            { key: "number", header: t("Entry"), render: (r) => <span className="font-medium text-ink-900">{r.number}<span className="block text-xs font-normal text-ink-500">{t(eventLabel(r.event))}</span></span> },
            { key: "description", header: t("Description"), render: (r) => <span className="line-clamp-2">{r.description}</span> },
            { key: "accounts", header: t("Accounts"), hideOnMobile: true, render: (r) => <span className="text-xs text-ink-600">{r.lines.slice(0, 3).map((l) => l.account?.name).join(", ")}{r.lines.length > 3 ? "…" : ""}</span> },
            { key: "total", header: t("Amount"), align: "right", render: (r) => <span className="num">{money(r.total)}</span> },
            { key: "status", header: "", render: (r) => r.status === "reversed" ? <Badge tone="red">{t("Reversed")}</Badge> : r.reversal_of_id ? <Badge tone="saffron">{t("Reversal")}</Badge> : r.is_manual ? <Badge tone="blue">{t("Manual")}</Badge> : null },
          ]}
          empty={{ title: t("No journal entries") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      <ManualJournal open={manual} onClose={() => setManual(false)} />
      <QuickEntry open={quick} onClose={() => setQuick(false)} />
      {openId && <EntryDetail id={openId} onClose={() => setOpenId(null)} />}
    </Guard>
  );
}
