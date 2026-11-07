"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, BellRing, Undo2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { date, money, qty, today, toNumber, humanize } from "@/lib/format";
import { useAuth } from "@/lib/auth";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import type { Channel } from "@/features/messaging/channels";
import { paymentMethodLabels } from "@/lib/lookups";
import { useAction, useApi } from "@/lib/hooks";
import { Guard, Can } from "@/components/layout/Guard";
import { Button, DataTable, DescriptionList, ErrorState, Field, Input, Modal, PageHeader, Panel, Spinner, StatusBadge, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Item = { id: number; quantity: string; returned_quantity: string; unit_cost: string; extra_cost_per_unit: string; landed_unit_cost: string; line_total: string; batch_no: string | null; expiry_date: string | null; product: { name: string; unit?: { short_name: string } | null } };
type Purchase = {
  id: number; number: string; purchase_date: string; supplier_invoice_no: string | null; cost_allocation: string; notes: string | null; due_date: string | null; payment_status: string;
  subtotal: string; tax_cost: string; loading_cost: string; unloading_cost: string; transport_cost: string; other_cost: string; total: string; supplier_total: string; amount_paid: string; balance: string; returned_amount: string;
  supplier?: { id: number; name: string } | null; branch: { name: string }; creator?: { name: string } | null; items: Item[];
  remind_on: string | null; reminder_channels: string[] | null; reminded_on: string | null; plan?: { id: number; number: string; status: string } | null;
  payments: Array<{ id: number; number: string; amount: string; method: string; paid_on: string; reference: string | null }>;
  returns: Array<{ id: number; number: string; total: string; reason: string; created_at: string }>;
};

export default function PurchaseDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const q = useApi<ApiResponse<Purchase>>(`admin/purchases/${id}`);
  const [returning, setReturning] = useState(false);
  const [lines, setLines] = useState<Record<number, number>>({});
  const [reason, setReason] = useState("");
  const ret = useAction(
    () => api.post(`admin/purchases/${id}/returns`, { reason, items: Object.entries(lines).filter(([, v]) => v > 0).map(([k, v]) => ({ purchase_item_id: Number(k), quantity: v })) }),
    { invalidate: [[`admin/purchases/${id}`], ["admin/purchases"]], onSuccess: () => { setReturning(false); setLines({}); setReason(""); } },
  );

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Purchase not found.")} />;
  const p = q.data.data;
  const extras = [["Transport", p.transport_cost], ["Loading", p.loading_cost], ["Unloading", p.unloading_cost], ["Tax / duty", p.tax_cost], ["Other", p.other_cost]].filter(([, v]) => toNumber(v) > 0);

  return (
    <Guard perm="purchases.view">
      <PageHeader
        back={<Link href="/admin/purchases" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Purchases")}</Link>}
        title={t("Purchase {number}", { number: p.number })}
        description={<span className="flex items-center gap-2">{date(p.purchase_date)} <StatusBadge status={p.payment_status} /></span>}
        actions={<Can perm="purchases.return"><Button variant="secondary" icon={<Undo2 className="size-4" />} onClick={() => setReturning(true)}>{t("Return to supplier")}</Button></Can>}
      />
      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <Panel title={t("Items")} padded={false}>
          <DataTable rows={p.items} rowKey={(r) => r.id} columns={[
            { key: "product.name", header: t("Product"), render: (r) => <span className="font-medium">{r.product.name}{(r.batch_no || r.expiry_date) && <span className="block text-xs font-normal text-ink-500">{r.batch_no && t("Batch {batch_no}", { batch_no: r.batch_no })} {r.expiry_date && t("exp {expiry_date}", { expiry_date: date(r.expiry_date) })}</span>}</span> },
            { key: "quantity", header: t("Qty"), align: "right", render: (r) => <>{qty(r.quantity, r.product.unit?.short_name)}{toNumber(r.returned_quantity) > 0 && <span className="block text-xs text-ember-600">{t("{returned_quantity} returned", { returned_quantity: qty(r.returned_quantity) })}</span>}</> },
            { key: "unit_cost", header: t("Supplier price"), align: "right", render: (r) => money(r.unit_cost) },
            { key: "extra_cost_per_unit", header: t("+ costs"), align: "right", hideOnMobile: true, render: (r) => money(r.extra_cost_per_unit) },
            { key: "landed_unit_cost", header: t("Landed / unit"), align: "right", render: (r) => <span className="font-semibold">{money(r.landed_unit_cost)}</span> },
            { key: "line_total", header: t("Amount"), align: "right", render: (r) => money(r.line_total) },
          ]} />
        </Panel>
        <div className="space-y-5">
          <Panel title={t("Summary")}>
            <DescriptionList items={[["Supplier", p.supplier ? <Link key="s" href={`/admin/suppliers/${p.supplier.id}`} className="text-brand-700 hover:underline">{p.supplier.name}</Link> : t("Cash purchase (no supplier)")], ["Their invoice", p.supplier_invoice_no], ["Branch", p.branch.name], ["Recorded by", p.creator?.name], ["Costs spread by", t(humanize(p.cost_allocation))], ["Due date", p.due_date ? date(p.due_date) : null]]} />
            <div className="mt-4 space-y-1 border-t border-ink-100 pt-3 text-sm">
              <div className="flex justify-between"><span>{t("Goods")}</span><span className="num">{money(p.subtotal)}</span></div>
              {extras.map(([k, v]) => <div key={k} className="flex justify-between text-ink-600"><span>{k}</span><span className="num">{money(v)}</span></div>)}
              <div className="flex justify-between font-semibold"><span>{t("Landed total")}</span><span className="num">{money(p.total)}</span></div>
              {toNumber(p.returned_amount) > 0 && <div className="flex justify-between text-ember-600"><span>{t("Returned")}</span><span className="num">-{money(p.returned_amount)}</span></div>}
              <div className="flex justify-between"><span>{t("Paid to supplier")}</span><span className="num">{money(p.amount_paid)}</span></div>
              <div className="flex justify-between font-semibold"><span>{t("Still owed")}</span><span className={`num ${toNumber(p.balance) > 0 ? "text-ember-600" : ""}`}>{money(p.balance)}</span></div>
            </div>
            {p.notes && <p className="mt-3 rounded-md bg-ink-50 px-3 py-2 text-sm">{p.notes}</p>}
          </Panel>
          {toNumber(p.balance) > 0 && <ReminderPanel purchase={p} onSaved={() => q.refetch()} />}
          {p.plan && <p className="text-sm text-ink-600">{t("Received against plan")} <Link href={`/admin/purchase-plans/${p.plan.id}`} className="font-medium text-brand-700 hover:underline">{p.plan.number}</Link></p>}
          {!!p.payments.length && <Panel title={t("Payments")}><ul className="space-y-2 text-sm">{p.payments.map((x) => <li key={x.id} className="flex justify-between"><span>{date(x.paid_on)}, {t(paymentMethodLabels[x.method] ?? humanize(x.method))}{x.reference && ` #${x.reference}`}</span><span className="num">{money(x.amount)}</span></li>)}</ul></Panel>}
          {!!p.returns.length && <Panel title={t("Returns")}><ul className="space-y-2 text-sm">{p.returns.map((r) => <li key={r.id}><div className="flex justify-between"><span className="font-medium">{r.number}</span><span className="num">{money(r.total)}</span></div><p className="text-xs text-ink-500">{r.reason}</p></li>)}</ul></Panel>}
        </div>
      </div>

      <Modal open={returning} onClose={() => setReturning(false)} title={t("Return goods to the supplier")} description={t("Stock is taken from the batch this purchase created. What we owe the supplier is reduced.")} size="lg"
        footer={<><Button variant="secondary" onClick={() => setReturning(false)}>{t("Cancel")}</Button><Button loading={ret.isPending} disabled={!Object.values(lines).some((v) => v > 0) || reason.trim().length < 3} onClick={() => ret.mutate(undefined)}>{t("Record return")}</Button></>}>
        <div className="space-y-4">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-ink-200 text-left text-[12.5px] text-ink-500"><th className="py-2">{t("Product")}</th><th className="py-2 text-right">{t("Can return")}</th><th className="py-2 text-right">{t("Return")}</th></tr></thead>
            <tbody>
              {p.items.map((i) => {
                const max = toNumber(i.quantity) - toNumber(i.returned_quantity);
                return (
                  <tr key={i.id} className="border-b border-ink-100">
                    <td className="py-2">{i.product.name}</td>
                    <td className="num py-2 text-right">{qty(max)}</td>
                    <td className="py-2 text-right"><Input aria-label={t("Return {name}", { name: i.product.name })} type="number" min={0} max={max} step="any" disabled={max <= 0} className="num ml-auto h-9 w-24 text-right" value={lines[i.id] || ""} onChange={(e) => setLines({ ...lines, [i.id]: Math.min(max, Number(e.target.value) || 0) })} /></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <Field label={t("Reason")} required>{(pp) => <Textarea {...pp} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t("e.g. Damaged on arrival, wrong item")} />}</Field>
        </div>
      </Modal>
    </Guard>
  );
}

/** When the company is reminded to pay this purchase, and how. */
function ReminderPanel({ purchase, onSaved }: { purchase: Purchase; onSaved: () => void }) {
  const t = useT();
  const { can } = useAuth();
  const [editing, setEditing] = useState(false);
  const [f, setF] = useState({ due_date: purchase.due_date?.slice(0, 10) ?? "", remind_on: purchase.remind_on?.slice(0, 10) ?? "" });
  const [channels, setChannels] = useState<Channel[]>((purchase.reminder_channels ?? []) as Channel[]);
  const save = useAction(() => api.put(`admin/purchases/${purchase.id}/reminder`, { due_date: f.due_date || null, remind_on: f.remind_on || null, reminder_channels: channels }), { onSuccess: () => { setEditing(false); onSaved(); } });
  const late = purchase.due_date && purchase.due_date.slice(0, 10) < today();
  const names: Record<string, string> = { sms: "SMS", whatsapp: "WhatsApp", email: t("Email") };
  return (
    <Panel title={<span className="flex items-center gap-1.5"><BellRing className="size-4" /> {t("Payment reminder")}</span>}
      actions={can(["purchases.credit", "suppliers.pay"]) && !editing && <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>{t("Change")}</Button>}>
      {!editing ? (
        <dl className="space-y-1 text-sm">
          <div className="flex justify-between"><dt className="text-ink-600">{t("Pay by")}</dt><dd className={late ? "font-semibold text-ember-600" : undefined}>{purchase.due_date ? date(purchase.due_date) : "—"}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-600">{t("Reminder on")}</dt><dd>{purchase.remind_on ? date(purchase.remind_on) : t("Not set")}</dd></div>
          <div className="flex justify-between"><dt className="text-ink-600">{t("Reminder by")}</dt><dd>{(purchase.reminder_channels ?? []).map((c) => names[c] ?? c).join(", ") || t("Bell only")}</dd></div>
          {purchase.reminded_on && <div className="flex justify-between"><dt className="text-ink-600">{t("Last reminded")}</dt><dd>{date(purchase.reminded_on)}</dd></div>}
          <p className="pt-1 text-xs text-ink-500">{t("A reminder goes on the reminder day and again on the due date while money is still owed.")}</p>
        </dl>
      ) : (
        <div className="space-y-3">
          <Field label={t("Pay by")}>{(p) => <Input {...p} type="date" value={f.due_date} onChange={(e) => setF({ ...f, due_date: e.target.value })} />}</Field>
          <Field label={t("Remind us on")} hint={t("Empty: a few days before (Settings)")}>{(p) => <Input {...p} type="date" max={f.due_date || undefined} value={f.remind_on} onChange={(e) => setF({ ...f, remind_on: e.target.value })} />}</Field>
          <ChannelPicker value={channels} onChange={setChannels} label={t("Remind by")} />
          <p className="text-xs text-ink-500">{t("SMS is short, WhatsApp a little longer, email has every detail and a PDF.")}</p>
          <div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setEditing(false)}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></div>
        </div>
      )}
    </Panel>
  );
}
