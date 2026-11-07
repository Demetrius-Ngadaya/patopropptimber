"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowDownLeft, ArrowLeft, ArrowUpRight, Lock, MapPin, Phone, Undo2, Unlock, XCircle } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, dateTime, money, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { paymentMethodLabels } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, Select, Spinner, Stat, Tabs, Textarea } from "@/components/ui";
import { FinancialHistory } from "@/features/crm/components/FinancialHistory";
import { MONEY_METHODS, SAVINGS_TYPE_LABELS, type SavingsAccount, type SavingsTransaction } from "@/features/savings/types";

type Detail = Paginated<SavingsTransaction> & {
  account: SavingsAccount;
  summary: { total_deposited: number; total_spent: number; total_withdrawn: number; balance: number; outstanding_debt: number };
};

function MoneyDialog({ kind, account, open, onClose }: { kind: "deposit" | "withdraw"; account: SavingsAccount; open: boolean; onClose: () => void }) {
  const t = useT();
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("cash");
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const save = useAction(
    () => api.post(`admin/savings/${account.id}/${kind === "deposit" ? "deposits" : "withdrawals"}`, { amount: Number(amount), method, reference: reference || undefined, notes: notes || undefined }),
    { invalidate: [["admin/savings"], [`admin/savings/${account.id}`], [`admin/customers/${account.customer_id}/financial-history`]], onSuccess: () => { onClose(); setAmount(""); setReference(""); setNotes(""); } },
  );
  const e = fieldErrors(save.error);
  const value = Number(amount) || 0;
  const balance = toNumber(account.balance);
  const after = kind === "deposit" ? balance + value : balance - value;

  return (
    <Modal open={open} onClose={onClose} title={kind === "deposit" ? t("Record a deposit") : t("Pay out savings")} description={account.customer?.name}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={value <= 0 || after < 0 || (kind === "withdraw" && notes.trim().length < 3)} onClick={() => save.mutate(undefined)}>{kind === "deposit" ? t("Save deposit") : t("Pay out")}</Button></>}>
      <div className="space-y-4">
        <Field label={t("Amount")} required error={e.amount}>{(p) => <Input {...p} type="number" inputMode="decimal" min={0} step="any" className="num text-right text-lg font-semibold" value={amount} onChange={(ev) => setAmount(ev.target.value)} autoFocus />}</Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={kind === "deposit" ? t("Paid with") : t("Paid out with")}>{(p) => <Select {...p} value={method} onChange={(ev) => setMethod(ev.target.value)}>{MONEY_METHODS.map((m) => <option key={m} value={m}>{t(paymentMethodLabels[m] ?? m)}</option>)}</Select>}</Field>
          {method !== "cash" && <Field label={t("Transaction ID")} required={kind === "deposit" && ["mobile_money", "bank", "card", "cheque"].includes(method)} error={e.reference}>{(p) => <Input {...p} value={reference} onChange={(ev) => setReference(ev.target.value)} />}</Field>}
        </div>
        <Field label={kind === "deposit" ? t("Note (optional)") : t("Reason")} required={kind === "withdraw"} error={e.notes}>{(p) => <Textarea {...p} rows={2} value={notes} onChange={(ev) => setNotes(ev.target.value)} />}</Field>
        <dl className="grid grid-cols-3 gap-2 rounded-lg bg-ink-50 p-3 text-sm">
          <div><dt className="text-ink-500">{t("Balance now")}</dt><dd className="num font-semibold">{money(balance)}</dd></div>
          <div><dt className="text-ink-500">{kind === "deposit" ? t("Adding") : t("Taking out")}</dt><dd className={cn("num font-semibold", kind === "deposit" ? "text-brand-700" : "text-saffron-700")}>{money(value)}</dd></div>
          <div><dt className="text-ink-500">{t("Balance after")}</dt><dd className={cn("num font-semibold", after < 0 && "text-ember-600")}>{money(after)}</dd></div>
        </dl>
      </div>
    </Modal>
  );
}

export default function SavingsAccountPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useT();
  const { can } = useAuth();
  const [tab, setTab] = useState<"transactions" | "history">("transactions");
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState<"deposit" | "withdraw" | null>(null);
  const [reversing, setReversing] = useState<SavingsTransaction | null>(null);
  const [statusChange, setStatusChange] = useState<"active" | "frozen" | "closed" | null>(null);
  const q = useApi<Detail>(`admin/savings/${id}`, { page });
  const d = q.data;
  const invalidate = [["admin/savings"], [`admin/savings/${id}`]];
  const reverse = useAction((v: { tx: number; reason: string }) => api.post(`admin/savings/${id}/transactions/${v.tx}/reverse`, { reason: v.reason }), { invalidate, onSuccess: () => setReversing(null) });
  const status = useAction((v: { status: string; reason: string }) => api.put(`admin/savings/${id}/status`, v), { invalidate, onSuccess: () => setStatusChange(null) });

  if (!d) return <Guard perm="savings.view"><Spinner variant="page" /></Guard>;
  const a = d.account;
  const active = a.status === "active";

  return (
    <Guard perm="savings.view">
      <PageHeader
        back={<Link href="/admin/savings" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Customer savings")}</Link>}
        title={a.customer?.name ?? a.account_no}
        description={<span className="flex flex-wrap items-center gap-x-3 gap-y-1"><span>{a.account_no}</span>{a.customer?.phone && <span className="inline-flex items-center gap-1"><Phone className="size-3.5" />{a.customer.phone}</span>}{a.customer?.address && <span className="inline-flex items-center gap-1"><MapPin className="size-3.5" />{a.customer.address}</span>}<Badge tone={active ? "green" : a.status === "frozen" ? "saffron" : "neutral"}>{t(active ? "Active" : a.status === "frozen" ? "Frozen" : "Closed")}</Badge></span>}
        actions={
          <>
            {can("savings.manage") && active && <Button variant="ghost" icon={<Lock className="size-4" />} onClick={() => setStatusChange("frozen")}>{t("Freeze")}</Button>}
            {can("savings.manage") && !active && a.status !== "closed" && <Button variant="ghost" icon={<Unlock className="size-4" />} onClick={() => setStatusChange("active")}>{t("Reactivate")}</Button>}
            {can("savings.manage") && a.status !== "closed" && toNumber(a.balance) === 0 && <Button variant="ghost" icon={<XCircle className="size-4" />} onClick={() => setStatusChange("closed")}>{t("Close account")}</Button>}
            {can("savings.withdraw") && active && toNumber(a.balance) > 0 && <Button variant="secondary" icon={<ArrowUpRight className="size-4" />} onClick={() => setDialog("withdraw")}>{t("Pay out")}</Button>}
            {can("savings.deposit") && active && <Button icon={<ArrowDownLeft className="size-4" />} onClick={() => setDialog("deposit")}>{t("Deposit")}</Button>}
          </>
        }
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <Stat label={t("Total savings deposited")} value={money(d.summary.total_deposited)} />
        <Stat label={t("Spent on purchases")} value={money(d.summary.total_spent)} />
        <Stat label={t("Paid back out")} value={money(d.summary.total_withdrawn)} />
        <Stat label={t("Remaining savings")} value={money(d.summary.balance)} tone="brand" />
        <Stat label={t("Outstanding debt")} value={money(d.summary.outstanding_debt)} tone={d.summary.outstanding_debt > 0 ? "saffron" : "default"} />
      </div>

      <div className="mb-4"><Tabs value={tab} onChange={setTab} tabs={[{ value: "transactions", label: t("Savings transactions") }, { value: "history", label: t("Full financial history") }]} /></div>

      {tab === "history" ? <FinancialHistory customerId={a.customer_id} /> : (
        <Panel padded={false}>
          <DataTable
            rows={d.data}
            loading={q.isFetching}
            rowKey={(r) => r.id}
            columns={[
              { key: "transacted_at", header: t("Date"), render: (r) => <span>{dateTime(r.transacted_at)}<span className="block text-xs text-ink-500">{r.number}</span></span> },
              { key: "type", header: t("Type"), render: (r) => <Badge tone={r.type === "deposit" || r.type === "refund" ? "green" : r.type === "reversal" ? "red" : r.type === "withdrawal" ? "saffron" : "blue"}>{t(SAVINGS_TYPE_LABELS[r.type])}</Badge> },
              { key: "amount", header: t("Amount"), align: "right", render: (r) => <span className={cn("num font-semibold", toNumber(r.balance_after) >= toNumber(r.balance_before) ? "text-brand-700" : "text-ink-900")}>{toNumber(r.balance_after) >= toNumber(r.balance_before) ? "+" : "−"}{money(r.amount)}</span> },
              { key: "purchase", header: t("Purchase / debt"), align: "right", hideOnMobile: true, render: (r) => r.purchase_amount ? <span className="num">{money(r.purchase_amount)}{toNumber(r.debt_amount) > 0 && <span className="block text-xs text-ember-600">{t("debt {amount}", { amount: money(r.debt_amount) })}</span>}</span> : "" },
              { key: "balance", header: t("Balance"), align: "right", render: (r) => <span className="num"><span className="text-xs text-ink-400">{money(r.balance_before)} → </span><span className="font-semibold">{money(r.balance_after)}</span></span> },
              { key: "method", header: t("Method"), hideOnMobile: true, render: (r) => <span>{r.method ? t(paymentMethodLabels[r.method] ?? r.method) : "—"}{r.sale && <Link href={`/admin/sales/${r.sale.id}`} className="block text-xs text-brand-700 hover:underline">{r.sale.number}</Link>}{r.notes && <span className="block text-xs text-ink-500">{r.notes}</span>}</span> },
              { key: "by", header: t("By"), hideOnMobile: true, render: (r) => r.creator?.name ?? "—" },
              ...(can("savings.manage") ? [{ key: "_x", header: "", align: "right" as const, render: (r: SavingsTransaction) => r.type === "deposit" ? <Button size="sm" variant="ghost" aria-label={t("Reverse deposit")} title={t("Reverse deposit")} onClick={() => setReversing(r)}><Undo2 className="size-4" /></Button> : null }] : []),
            ]}
            empty={{ title: t("No transactions yet") }}
          />
          <Pagination meta={d.meta} onPage={setPage} />
        </Panel>
      )}

      {dialog && <MoneyDialog kind={dialog} account={a} open onClose={() => setDialog(null)} />}
      <ConfirmDialog open={Boolean(reversing)} onClose={() => setReversing(null)} requireReason loading={reverse.isPending}
        onConfirm={(reason) => reversing && reverse.mutate({ tx: reversing.id, reason })}
        title={t("Reverse this deposit?")} message={reversing ? t("Deposit {number} of {amount} will be taken back out of the savings. The original stays in the history.", { number: reversing.number, amount: money(reversing.amount) }) : ""} confirmLabel={t("Reverse deposit")} />
      <ConfirmDialog open={Boolean(statusChange)} onClose={() => setStatusChange(null)} requireReason loading={status.isPending} tone={statusChange === "active" ? "primary" : "danger"}
        onConfirm={(reason) => statusChange && status.mutate({ status: statusChange, reason })}
        title={statusChange === "frozen" ? t("Freeze this account?") : statusChange === "closed" ? t("Close this account?") : t("Reactivate this account?")}
        message={statusChange === "frozen" ? t("No deposits, purchases or withdrawals until it is reactivated.") : statusChange === "closed" ? t("A closed account cannot be used again.") : t("The customer can deposit and buy again.")}
        confirmLabel={statusChange === "frozen" ? t("Freeze") : statusChange === "closed" ? t("Close account") : t("Reactivate")} />
    </Guard>
  );
}
