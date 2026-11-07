"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, CalendarClock, HandCoins, Phone, MapPin, Receipt } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money, today } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { paymentMethodLabels } from "@/lib/lookups";
import type { Sale } from "@/lib/types";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Field, Input, Modal, PageHeader, Panel, Select, Spinner, Stat, Textarea } from "@/components/ui";
import { creditStatusTone, type CreditPayment, type CreditSummary } from "@/features/credit/types";

type Detail = { sale: Sale & { customer?: { id: number; name: string; phone: string | null; address: string | null } | null }; summary: CreditSummary; payments: CreditPayment[] };
const METHODS = ["cash", "mobile_money", "lipa_namba", "bank", "card", "cheque", "savings"];

function PayDialog({ summary, open, onClose }: { summary: CreditSummary; open: boolean; onClose: () => void }) {
  const t = useT();
  const [mode, setMode] = useState<"full" | "partial">("full");
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("cash");
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const pay = useAction(
    () => api.post(`admin/credit-sales/${summary.sale_id}/payments`, { mode, amount: mode === "partial" ? Number(amount) : undefined, method, reference: reference || undefined, notes: notes || undefined }),
    { invalidate: [["admin/credit-sales"], [`admin/credit-sales/${summary.sale_id}`]], onSuccess: () => { onClose(); setAmount(""); setReference(""); setNotes(""); } },
  );
  const errors = fieldErrors(pay.error);
  const partial = Number(amount) || 0;
  const after = mode === "full" ? 0 : Math.max(0, summary.remaining_balance - partial);
  const tooMuch = mode === "partial" && partial > summary.remaining_balance + 0.004;

  return (
    <Modal open={open} onClose={onClose} title={t("Record a repayment")} description={t("{customer} owes {amount} on {number}.", { customer: summary.customer_name ?? "", amount: money(summary.remaining_balance), number: summary.number })}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={pay.isPending} disabled={(mode === "partial" && (partial <= 0 || tooMuch)) || (["mobile_money", "bank", "card", "cheque"].includes(method) && !reference.trim())} onClick={() => pay.mutate(undefined)}>{t("Save payment")}</Button></>}
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label={t("Payment type")}>
          {(["full", "partial"] as const).map((m) => (
            <button key={m} type="button" role="radio" aria-checked={mode === m} onClick={() => setMode(m)}
              className={`rounded-lg p-3 text-left ring-1 transition ${mode === m ? "bg-brand-50 ring-2 ring-brand-500" : "ring-ink-200 hover:bg-ink-50"}`}>
              <p className="text-sm font-semibold text-ink-900">{m === "full" ? t("Full payment") : t("Partial payment")}</p>
              <p className="mt-0.5 text-xs text-ink-500">{m === "full" ? t("Pays {amount}. The debt is closed.", { amount: money(summary.remaining_balance) }) : t("Pays part; the rest stays owed.")}</p>
            </button>
          ))}
        </div>
        {mode === "partial" && (
          <Field label={t("Amount paid")} required error={errors.amount ?? (tooMuch ? t("More than the remaining balance.") : undefined)}>
            {(p) => <Input {...p} type="number" inputMode="decimal" min={0} step="any" className="num text-right text-lg font-semibold" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />}
          </Field>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t("Paid with")}>{(p) => <Select {...p} value={method} onChange={(e) => setMethod(e.target.value)}>{METHODS.map((m) => <option key={m} value={m}>{t(paymentMethodLabels[m] ?? m)}</option>)}</Select>}</Field>
          {method !== "cash" && method !== "savings" && <Field label={t("Transaction ID")} required={["mobile_money", "bank", "card", "cheque"].includes(method)} error={errors.reference}>{(p) => <Input {...p} value={reference} onChange={(e) => setReference(e.target.value)} />}</Field>}
        </div>
        <Field label={t("Note (optional)")}>{(p) => <Textarea {...p} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={500} />}</Field>
        <dl className="grid grid-cols-3 gap-2 rounded-lg bg-ink-50 p-3 text-sm">
          <div><dt className="text-ink-500">{t("Owed now")}</dt><dd className="num font-semibold">{money(summary.remaining_balance)}</dd></div>
          <div><dt className="text-ink-500">{t("Paying")}</dt><dd className="num font-semibold text-brand-700">{money(mode === "full" ? summary.remaining_balance : partial)}</dd></div>
          <div><dt className="text-ink-500">{t("Left after")}</dt><dd className="num font-semibold">{money(after)}</dd></div>
        </dl>
      </div>
    </Modal>
  );
}

function RescheduleDialog({ summary, open, onClose }: { summary: CreditSummary; open: boolean; onClose: () => void }) {
  const t = useT();
  const [due, setDue] = useState(summary.expected_repayment_date ?? "");
  const [reason, setReason] = useState("");
  const save = useAction(() => api.put(`admin/credit-sales/${summary.sale_id}/due-date`, { due_date: due, reason }), {
    invalidate: [["admin/credit-sales"], [`admin/credit-sales/${summary.sale_id}`]], onSuccess: onClose,
  });
  return (
    <Modal open={open} onClose={onClose} title={t("Change the repayment date")} size="sm"
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={!due || reason.trim().length < 3} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></>}>
      <div className="space-y-4">
        <Field label={t("New expected repayment date")} required>{(p) => <Input {...p} type="date" min={today()} value={due} onChange={(e) => setDue(e.target.value)} />}</Field>
        <Field label={t("Reason")} required hint={t("Kept in the audit log.")}>{(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>
      </div>
    </Modal>
  );
}

export default function CreditSaleDetail({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useT();
  const { can } = useAuth();
  const [paying, setPaying] = useState(false);
  const [rescheduling, setRescheduling] = useState(false);
  const q = useApi<ApiResponse<Detail>>(`admin/credit-sales/${id}`);
  const d = q.data?.data;

  return (
    <Guard perm="credit.view">
      {!d ? <Spinner /> : (
        <>
          <PageHeader
            back={<Link href="/admin/credit-sales" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Credit sales")}</Link>}
            title={t("Credit sale {number}", { number: d.summary.number })}
            description={t("Sold on {date}", { date: dateTime(d.sale.sold_at) })}
            actions={
              <>
                {can("credit.reschedule") && d.summary.remaining_balance > 0 && <Button variant="secondary" icon={<CalendarClock className="size-4" />} onClick={() => setRescheduling(true)}>{t("Change date")}</Button>}
                {can("credit.receive_payment") && d.summary.remaining_balance > 0 && <Button icon={<HandCoins className="size-4" />} onClick={() => setPaying(true)}>{t("Record payment")}</Button>}
              </>
            }
          />
          <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label={t("Original amount owed")} value={money(d.summary.original_amount)} />
            <Stat label={t("Amount paid")} value={money(d.summary.amount_paid)} tone="brand" />
            <Stat label={t("Remaining balance")} value={money(d.summary.remaining_balance)} tone={d.summary.remaining_balance > 0 ? "saffron" : "brand"} />
            <Stat
              label={t("Expected repayment date")}
              value={d.summary.expected_repayment_date ? date(d.summary.expected_repayment_date) : "—"}
              sub={d.summary.days_overdue > 0 ? <span className="text-ember-600">{t("{days} day(s) overdue", { days: d.summary.days_overdue })}</span> : undefined}
              tone={d.summary.days_overdue > 0 ? "ember" : "default"}
            />
          </div>

          <div className="grid gap-5 lg:grid-cols-[320px_1fr]">
            <div className="space-y-5">
              <Panel title={t("Customer")}>
                <p className="font-semibold text-ink-900">{d.summary.customer_name ?? "—"}</p>
                {d.summary.customer_phone && <p className="mt-1.5 flex items-center gap-1.5 text-sm text-ink-600"><Phone className="size-4" /> <a href={`tel:${d.summary.customer_phone}`} className="hover:underline">{d.summary.customer_phone}</a></p>}
                {d.summary.customer_address && <p className="mt-1 flex items-start gap-1.5 text-sm text-ink-600"><MapPin className="mt-0.5 size-4 shrink-0" /> {d.summary.customer_address}</p>}
                <div className="mt-3"><Badge tone={creditStatusTone[d.summary.status]}>{d.summary.status === "fully_paid" ? t("Fully paid") : d.summary.status === "partially_paid" ? t("Partly paid") : t("Not paid")}</Badge></div>
                {d.sale.customer_id && <Link href={`/admin/customers/${d.sale.customer_id}?tab=finance`} className="mt-3 block text-sm font-medium text-brand-700 hover:underline">{t("Full financial history")}</Link>}
              </Panel>
              <Panel title={t("Goods taken")} actions={<Link href={`/admin/sales/${d.sale.id}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><Receipt className="size-4" /> {t("Sale")}</Link>}>
                <ul className="divide-y divide-ink-100 text-sm">
                  {d.sale.items?.map((i) => (
                    <li key={i.id} className="flex justify-between gap-2 py-1.5"><span>{i.product_name} <span className="text-ink-500">× {Number(i.quantity)}</span></span><span className="num">{money(i.line_total)}</span></li>
                  ))}
                </ul>
                <p className="mt-2 flex justify-between border-t border-ink-200 pt-2 font-semibold"><span>{t("Total")}</span><span className="num">{money(d.sale.total)}</span></p>
              </Panel>
            </div>

            <Panel title={t("Payment history")} padded={false}>
              <DataTable
                rows={d.payments}
                rowKey={(r) => r.id}
                columns={[
                  { key: "paid_at", header: t("Date"), render: (r) => dateTime(r.paid_at) },
                  { key: "balance_before", header: t("Owed before"), align: "right", render: (r) => <span className="num">{money(r.balance_before)}</span> },
                  { key: "amount", header: t("Paid"), align: "right", render: (r) => <span className="num font-semibold text-brand-700">{money(r.amount)}</span> },
                  { key: "balance_after", header: t("Remaining"), align: "right", render: (r) => r.balance_after > 0 ? <span className="num">{money(r.balance_after)}</span> : <Badge tone="green">{t("Settled")}</Badge> },
                  { key: "method", header: t("Method"), hideOnMobile: true, render: (r) => <span>{t(paymentMethodLabels[r.method] ?? r.method)}{r.reference && <span className="block text-xs text-ink-500">#{r.reference}</span>}</span> },
                  { key: "received_by", header: t("Received by"), hideOnMobile: true, render: (r) => r.received_by ?? "—" },
                ]}
                empty={{ title: t("No repayments yet"), message: t("Payments appear here with the balance before and after each one.") }}
              />
            </Panel>
          </div>

          {can("credit.receive_payment") && <PayDialog summary={d.summary} open={paying} onClose={() => setPaying(false)} />}
          {can("credit.reschedule") && rescheduling && <RescheduleDialog summary={d.summary} open={rescheduling} onClose={() => setRescheduling(false)} />}
        </>
      )}
    </Guard>
  );
}
