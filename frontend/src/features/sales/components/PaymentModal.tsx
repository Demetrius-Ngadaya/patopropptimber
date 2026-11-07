"use client";

import { useEffect, useMemo, useState } from "react";
import { PiggyBank, Plus, Trash2, UserPlus } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, money, today, toNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import type { Channel } from "@/features/messaging/channels";
import { paymentMethodLabels, useLookups } from "@/lib/lookups";
import type { Customer } from "@/lib/types";
import { Button, Checkbox, Field, Input, Modal, Select, Textarea } from "@/components/ui";
import { PhonePayment } from "@/features/payments/PhonePayment";

/** online_payment_id: a payment by phone the gateway confirmed (method "online"). */
export type PaymentLine = { method: string; amount: number; provider?: string; reference?: string; online_payment_id?: number };
export type NewCreditCustomer = { name: string; phone: string; address: string };
export type CheckoutExtras = {
  payments: PaymentLine[];
  due_date?: string;
  notes?: string;
  /** Ways to send the receipt; undefined = follow the setting. */
  receipt_channels?: Channel[];
  /** Walk-in customer registered on the spot for a credit sale. */
  new_customer?: NewCreditCustomer;
  /** Take whatever the customer's savings cover; any rest becomes credit. */
  use_savings?: boolean;
};

type FinancialSummary = {
  savings: { balance: number; status: string; total_deposited: number } | null;
  credit: { outstanding: number; overdue: number };
};

type Props = {
  open: boolean;
  onClose: () => void;
  total: number;
  customer: Customer | null;
  loading: boolean;
  onConfirm: (data: CheckoutExtras) => void;
  title?: string;
  confirmLabel?: string;
  /** Savings and walk-in credit registration are offered at the till only. */
  tillFeatures?: boolean;
};

const needsReference = (m: string) => ["mobile_money", "bank", "cheque", "card"].includes(m);
const PHONE = /^\+?[0-9 ]{9,15}$/;

/**
 * Payment at the till: cash / mobile money / bank / split, paying from
 * customer savings, and credit sales. A credit sale always records the
 * customer's phone, residential address and the expected repayment date.
 */
export function PaymentModal({ open, onClose, total, customer, loading, onConfirm, title, confirmLabel, tillFeatures = true }: Props) {
  const t = useT();
  const { can } = useAuth();
  const { payment } = useLookups(["payment"]);
  const baseMethods = (payment?.methods ?? ["cash", "mobile_money", "lipa_namba", "bank", "card", "cheque"]).filter((m) => m !== "credit_note" && m !== "savings" && m !== "online");
  // Payment by phone through the gateway, when the admin switched it on for the till.
  const phoneOn = tillFeatures && Boolean(payment?.online);
  const methods = phoneOn ? [baseMethods[0], "online", ...baseMethods.slice(1)] : baseMethods;
  const providers = payment?.providers ?? [];

  const [lines, setLines] = useState<PaymentLine[]>([{ method: "cash", amount: total }]);
  const [useSavings, setUseSavings] = useState(false);
  const [dueDate, setDueDate] = useState("");
  const [notes, setNotes] = useState("");
  const [receipt, setReceipt] = useState<Channel[] | undefined>(undefined);
  const [walkIn, setWalkIn] = useState<NewCreditCustomer>({ name: "", phone: "", address: "" });
  const [waiting, setWaiting] = useState<Record<number, boolean>>({});

  const finance = useApi<ApiResponse<FinancialSummary>>(open && customer && tillFeatures && can(["savings.view", "credit.view"]) ? `admin/customers/${customer.id}/financial-history` : null);
  const savingsBalance = finance.data?.data.savings?.status === "active" ? toNumber(finance.data.data.savings.balance) : 0;

  useEffect(() => {
    if (!open) return;
    setLines([{ method: "cash", amount: total }]);
    setUseSavings(false);
    setDueDate("");
    setNotes("");
    setReceipt(undefined);
    setWalkIn({ name: "", phone: "", address: "" });
    setWaiting({});
  }, [open, total]);

  // Savings pay first; other methods cover the rest.
  useEffect(() => {
    if (!open) return;
    if (useSavings) {
      const rest = Math.max(0, total - Math.min(savingsBalance, total));
      setLines([{ method: "cash", amount: Number(rest.toFixed(2)) }]);
    }
  }, [useSavings, savingsBalance, total, open]);

  const fromSavings = useSavings ? Math.min(savingsBalance, total) : 0;
  const othersPaid = useMemo(() => lines.reduce((s, l) => s + toNumber(l.amount), 0), [lines]);
  const paid = othersPaid + fromSavings;
  const cash = useMemo(() => lines.filter((l) => l.method === "cash").reduce((s, l) => s + toNumber(l.amount), 0), [lines]);
  const change = Math.max(0, paid - total);
  const balance = Math.max(0, total - paid);
  const onCredit = balance > 0.004;
  const registeringWalkIn = onCredit && !customer && tillFeatures;

  const walkInValid = walkIn.name.trim().length >= 2 && PHONE.test(walkIn.phone.trim()) && walkIn.address.trim().length >= 3;
  const creditProblem = onCredit
    ? !can("pos.credit_sale")
      ? t("You are not allowed to sell on credit. Collect the full amount.")
      : !customer && !tillFeatures
        ? t("Select a registered customer to sell on credit, or collect the full amount.")
        : customer && !customer.allow_credit
          ? t("{name} is not allowed to buy on credit.", { name: customer.name })
          : !dueDate
            ? t("Enter the date the customer will pay the balance.")
            : registeringWalkIn && !walkInValid
              ? t("Enter the customer's name, phone number and residential address for the credit sale.")
              : null
    : null;
  const changeProblem = change > 0.004 && change - cash > 0.004 ? t("Only cash can be more than the total (for change).") : null;
  const referenceMissing = lines.some((l) => needsReference(l.method) && toNumber(l.amount) > 0 && !l.reference?.trim());
  const phoneUnpaid = lines.some((l) => l.method === "online" && toNumber(l.amount) > 0 && !l.online_payment_id);

  const update = (i: number, patch: Partial<PaymentLine>) => setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  const submit = () => {
    onConfirm({
      payments: lines.filter((l) => toNumber(l.amount) > 0).map((l) => ({ ...l, amount: toNumber(l.amount) })),
      due_date: onCredit ? dueDate : undefined,
      notes: notes || undefined,
      receipt_channels: receipt,
      use_savings: useSavings || undefined,
      new_customer: registeringWalkIn ? { name: walkIn.name.trim(), phone: walkIn.phone.trim(), address: walkIn.address.trim() } : undefined,
    });
  };

  const inDays = (n: number) => {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title ?? t("Take payment")}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t("Back to cart")}</Button>
          <Button size="lg" onClick={submit} loading={loading} disabled={Boolean(creditProblem || changeProblem || referenceMissing || phoneUnpaid)}>
            {confirmLabel ?? (onCredit ? t("Complete credit sale") : t("Complete sale"))}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <div className="keep-light flex items-baseline justify-between rounded-lg bg-night-900 px-4 py-3 text-white">
          <span className="text-sm text-ink-300">{t("Amount due")}</span>
          <span className="num text-3xl font-semibold tracking-tight">{money(total)}</span>
        </div>

        {/* Customer savings */}
        {tillFeatures && customer && savingsBalance > 0 && (
          <div className={cn("rounded-lg p-3 ring-1", useSavings ? "bg-brand-50 ring-brand-200" : "ring-ink-200")}>
            <Checkbox
              label={
                <span className="flex items-center gap-1.5 font-medium text-ink-900">
                  <PiggyBank className="size-4 text-brand-700" />
                  {t("Pay from {name}'s savings", { name: customer.name })}
                </span>
              }
              checked={useSavings}
              onChange={(e) => setUseSavings(e.target.checked)}
            />
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-[13px] sm:grid-cols-4">
              <div><dt className="text-ink-500">{t("Savings balance")}</dt><dd className="num font-semibold">{money(savingsBalance)}</dd></div>
              <div><dt className="text-ink-500">{t("Purchase")}</dt><dd className="num font-semibold">{money(total)}</dd></div>
              <div><dt className="text-ink-500">{t("Deducted")}</dt><dd className="num font-semibold text-brand-700">{money(fromSavings)}</dd></div>
              <div><dt className="text-ink-500">{t("Savings left")}</dt><dd className="num font-semibold">{money(savingsBalance - fromSavings)}</dd></div>
            </dl>
            {useSavings && savingsBalance < total && (
              <p className="mt-2 text-[13px] text-saffron-700">
                {t("Savings cover {covered}. The remaining {rest} can be paid now or left as a credit debt.", { covered: money(fromSavings), rest: money(total - fromSavings) })}
              </p>
            )}
          </div>
        )}

        <div className="space-y-3">
          {lines.map((line, i) => {
            // A phone payment that is out or confirmed fixes its method and amount.
            const locked = line.method === "online" && (Boolean(line.online_payment_id) || Boolean(waiting[i]));
            return (
            <div key={i} className="grid grid-cols-[1fr_1fr_auto] gap-2 rounded-lg p-3 ring-1 ring-ink-200 sm:grid-cols-[150px_1fr_auto]">
              <Select aria-label={t("Payment method")} value={line.method} disabled={locked} onChange={(e) => update(i, { method: e.target.value, provider: undefined, reference: undefined, online_payment_id: undefined })}>
                {methods.map((m) => <option key={m} value={m}>{t(paymentMethodLabels[m] ?? m)}</option>)}
              </Select>
              <Input aria-label={t("Amount")} type="number" inputMode="decimal" min={0} step="any" className="num text-right text-base font-semibold" disabled={locked} value={line.amount === 0 ? "" : line.amount} onChange={(e) => update(i, { amount: e.target.value === "" ? 0 : Number(e.target.value) })} onFocus={(e) => e.target.select()} />
              <Button variant="ghost" size="md" aria-label={t("Remove payment")} disabled={lines.length === 1 || locked} onClick={() => setLines((ls) => ls.filter((_, idx) => idx !== i))}>
                <Trash2 className="size-4" />
              </Button>
              {(line.method === "mobile_money" || line.method === "bank" || line.method === "lipa_namba") && (
                <Select aria-label={t("Provider")} className="col-span-1" value={line.provider ?? ""} onChange={(e) => update(i, { provider: e.target.value || undefined })}>
                  <option value="">{line.method === "bank" ? t("Bank") : t("Network")}…</option>
                  {providers.map((p) => <option key={p} value={p}>{p}</option>)}
                </Select>
              )}
              {line.method === "online" && (
                <PhonePayment
                  amount={toNumber(line.amount)}
                  minimum={payment?.online_minimum ?? 500}
                  defaultPhone={customer?.phone ?? (walkIn.phone || null)}
                  payerName={customer?.name ?? (walkIn.name || null)}
                  customerId={customer?.id}
                  onPaid={(p) => update(i, { online_payment_id: p?.id })}
                  onWaiting={(w) => setWaiting((s) => (Boolean(s[i]) === w ? s : { ...s, [i]: w }))}
                />
              )}
              {line.method !== "cash" && line.method !== "online" && (
                <Input
                  aria-label={t("Transaction reference")}
                  placeholder={needsReference(line.method) ? t("Transaction ID (required)") : t("Reference (optional)")}
                  className={cn("col-span-2", line.method === "card" || line.method === "cheque" ? "sm:col-span-2" : "sm:col-span-1")}
                  value={line.reference ?? ""}
                  onChange={(e) => update(i, { reference: e.target.value })}
                />
              )}
            </div>
            );
          })}
          <div className="flex flex-wrap gap-2">
            {lines.length < 4 && (
              <Button variant="secondary" size="sm" icon={<Plus className="size-4" />} onClick={() => setLines((ls) => [...ls, { method: "mobile_money", amount: Math.max(0, Number((total - paid).toFixed(2))) }])}>
                {t("Split payment")}
              </Button>
            )}
            {can("pos.credit_sale") && (
              <Button variant="ghost" size="sm" onClick={() => setLines([{ method: "cash", amount: 0 }])}>{t("Nothing paid now (credit)")}</Button>
            )}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3 text-sm">
          <div className="rounded-lg bg-ink-50 px-3 py-2">
            <p className="text-ink-500">{t("Paid")}</p>
            <p className="num text-lg font-semibold">{money(paid)}</p>
          </div>
          {change > 0 ? (
            <div className="rounded-lg bg-brand-50 px-3 py-2">
              <p className="text-brand-700">{t("Change to give")}</p>
              <p className="num text-lg font-semibold text-brand-700">{money(change)}</p>
            </div>
          ) : (
            <div className={cn("rounded-lg px-3 py-2", onCredit ? "bg-saffron-50" : "bg-ink-50")}>
              <p className={onCredit ? "text-saffron-700" : "text-ink-500"}>{onCredit ? t("On credit (debt)") : t("Balance")}</p>
              <p className={cn("num text-lg font-semibold", onCredit && "text-saffron-700")}>{money(balance)}</p>
            </div>
          )}
        </div>

        {/* Credit sale details */}
        {onCredit && can("pos.credit_sale") && (!customer || customer.allow_credit) && (
          <fieldset className="space-y-3 rounded-lg bg-saffron-50/60 p-3 ring-1 ring-saffron-100">
            <legend className="sr-only">{t("Credit sale details")}</legend>
            {customer ? (
              <div className="text-[13px] text-ink-700">
                <p className="font-semibold text-ink-900">{customer.name}</p>
                <p>{customer.phone ?? t("No phone number")}{customer.address ? ` · ${customer.address}` : ""}</p>
                {finance.data?.data.credit && toNumber(finance.data.data.credit.outstanding) > 0 && (
                  <p className="mt-1 text-saffron-700">
                    {t("Already owes {owed}", { owed: money(finance.data.data.credit.outstanding) })}
                    {toNumber(finance.data.data.credit.overdue) > 0 && ` · ${t("{overdue} overdue", { overdue: money(finance.data.data.credit.overdue) })}`}
                  </p>
                )}
                {toNumber(customer.credit_limit) > 0 && <p className="text-ink-500">{t("Credit limit {limit}", { limit: money(customer.credit_limit) })}</p>}
              </div>
            ) : (
              <>
                <p className="flex items-center gap-1.5 text-[13px] font-medium text-ink-800"><UserPlus className="size-4" /> {t("New credit customer (registered automatically)")}</p>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label={t("Full name")} required>{(p) => <Input {...p} value={walkIn.name} onChange={(e) => setWalkIn({ ...walkIn, name: e.target.value })} autoComplete="off" />}</Field>
                  <Field label={t("Phone number")} required hint={t("If this number is already registered, that customer is used.")}>
                    {(p) => <Input {...p} type="tel" inputMode="tel" placeholder="0712 345 678" value={walkIn.phone} onChange={(e) => setWalkIn({ ...walkIn, phone: e.target.value })} />}
                  </Field>
                </div>
                <Field label={t("Residential address")} required>{(p) => <Input {...p} placeholder={t("e.g. Sinza Mori, near the market")} value={walkIn.address} onChange={(e) => setWalkIn({ ...walkIn, address: e.target.value })} />}</Field>
              </>
            )}
            <Field label={t("Expected repayment date")} required>
              {(p) => (
                <div className="flex flex-wrap items-center gap-2">
                  <Input {...p} type="date" min={today()} value={dueDate} onChange={(e) => setDueDate(e.target.value)} className="w-44" />
                  {[7, 14, 30].map((n) => (
                    <Button key={n} variant="secondary" size="sm" onClick={() => setDueDate(inDays(n))}>{t("{n} days", { n })}</Button>
                  ))}
                </div>
              )}
            </Field>
          </fieldset>
        )}

        {(creditProblem || changeProblem || referenceMissing) && (
          <p role="alert" className="rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">
            {creditProblem ?? changeProblem ?? t("Enter the transaction ID for mobile money, bank, card and cheque payments.")}
          </p>
        )}
        {!creditProblem && !changeProblem && !referenceMissing && phoneUnpaid && (
          <p className="rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-800">{t("The sale can be completed once the customer has paid by phone.")}</p>
        )}

        <Field label={t("Note on the receipt")}>
          {(p) => <Textarea {...p} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={500} />}
        </Field>
        {tillFeatures && (
          <ChannelPicker
            label={t("Send the receipt to the customer by")}
            value={receipt ?? []}
            onChange={setReceipt}
            missing={{
              ...(!(customer?.phone || walkIn.phone) ? { sms: t("No phone number"), whatsapp: t("No phone number") } : {}),
              ...(!customer?.email ? { email: t("No email address") } : {}),
            }}
          />
        )}
      </div>
    </Modal>
  );
}
