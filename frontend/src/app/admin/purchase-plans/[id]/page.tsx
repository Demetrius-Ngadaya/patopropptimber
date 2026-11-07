"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useState } from "react";
import { ArrowLeft, Ban, CheckCircle2, Lock, PackageCheck, Pencil, Send, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, date, dateTime, money, qty, today, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DescriptionList, ErrorState, Field, Input, Modal, PageHeader, Panel, Select, Spinner, StatusBadge, Textarea } from "@/components/ui";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import type { Channel } from "@/features/messaging/channels";
import { PLAN_EVENT_LABELS, PLAN_STATUS_LABELS, PLAN_STATUS_TONES, type PurchasePlan } from "@/features/purchase-plans/types";

type Dialog = null | "submit" | "receive" | "cancel" | "close" | "delete";

export default function PurchasePlanPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const router = useRouter();
  const { can } = useAuth();
  const path = `admin/purchase-plans/${id}`;
  const q = useApi<ApiResponse<PurchasePlan>>(path, undefined, { keepPrevious: false });
  const [dialog, setDialog] = useState<Dialog>(null);
  const close = () => setDialog(null);
  const inv = [[path], ["admin/purchase-plans"]];
  const act = useAction((v: { action: string; body?: unknown }) => api.post(`${path}/${v.action}`, v.body ?? {}), { invalidate: inv, onSuccess: close });
  const remove = useAction(() => api.del(path), { invalidate: [["admin/purchase-plans"]], onSuccess: () => router.push("/admin/purchase-plans") });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Not found.")} />;
  const p = q.data.data;
  const items = p.items ?? [];
  const receivable = p.status === "pending" || p.status === "partially_received";

  return (
    <Guard perm="purchase_plans.view">
      <PageHeader
        back={<Link href="/admin/purchase-plans" className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Purchase planning")}</Link>}
        title={`${p.number}${p.title ? ` · ${p.title}` : ""}`}
        description={<span className="flex flex-wrap items-center gap-2"><Badge tone={PLAN_STATUS_TONES[p.status]}>{t(PLAN_STATUS_LABELS[p.status])}</Badge>{p.supplier?.name}<span className="num font-semibold text-ink-800">{money(p.estimated_total)}</span></span>}
        actions={<>
          {can("purchase_plans.create") && ["draft", "pending"].includes(p.status) && <Link href={`/admin/purchase-plans/${p.id}/edit`}><Button variant="secondary" icon={<Pencil className="size-4" />}>{t("Edit")}</Button></Link>}
          {can("purchase_plans.approve") && p.status === "draft" && <Button icon={<Send className="size-4" />} onClick={() => setDialog("submit")}>{t("Approve & make pending")}</Button>}
          {can("purchase_plans.receive") && receivable && <Button icon={<PackageCheck className="size-4" />} onClick={() => setDialog("receive")}>{t("Receive goods")}</Button>}
        </>}
      />
      {p.status === "cancelled" && p.cancel_reason && <p className="mb-4 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{t("Cancelled: {reason}", { reason: p.cancel_reason })}</p>}

      <div className="grid gap-5 xl:grid-cols-[1fr_340px]">
        <div className="space-y-5">
          <Panel title={t("Products")} padded={false}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-ink-50/60 text-left text-[12.5px] text-ink-600">
                  <tr><th className="px-4 py-2 font-semibold">{t("Product")}</th><th className="px-3 py-2 text-right font-semibold">{t("Ordered")}</th><th className="px-3 py-2 text-right font-semibold">{t("Received")}</th><th className="px-3 py-2 text-right font-semibold">{t("Still to come")}</th><th className="px-4 py-2 text-right font-semibold">{t("Expected cost")}</th></tr>
                </thead>
                <tbody>
                  {items.map((i) => (
                    <tr key={i.id} className="border-t border-ink-100">
                      <td className="px-4 py-2">{i.product?.name}{i.note && <span className="block text-xs text-ink-500">{i.note}</span>}</td>
                      <td className="num px-3 py-2 text-right">{qty(i.quantity, i.product?.unit?.short_name)}</td>
                      <td className="num px-3 py-2 text-right">{qty(i.received_quantity)}</td>
                      <td className={cn("num px-3 py-2 text-right", i.remaining_quantity > 0 && receivable && "font-semibold text-saffron-700")}>{qty(i.remaining_quantity)}</td>
                      <td className="num px-4 py-2 text-right">{money(i.unit_cost)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          {p.purchases && p.purchases.length > 0 && (
            <Panel title={t("Deliveries received")} padded={false}>
              <ul className="divide-y divide-ink-100 text-sm">
                {p.purchases.map((x) => (
                  <li key={x.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                    <Link href={`/admin/purchases/${x.id}`} className="font-medium text-brand-700 hover:underline">{x.number}<span className="ml-2 font-normal text-ink-500">{date(x.purchase_date)}</span></Link>
                    <span className="flex items-center gap-2">
                      <span className="num">{money(x.total)}</span>
                      {toNumber(x.balance) > 0 && <span className="text-xs text-ember-600">{t("{amount} owed, pay by {date}", { amount: money(x.balance), date: date(x.due_date) })}</span>}
                      <StatusBadge status={x.payment_status} />
                    </span>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </div>
        <div className="space-y-5">
          <Panel title={t("Details")}>
            <DescriptionList items={[
              ["Supplier", p.supplier?.name ?? t("Not chosen yet")],
              ["Branch", p.branch?.name],
              ["Goods expected on", p.expected_date ? date(p.expected_date) : null],
              ["Payment", p.payment_type === "credit" ? t("Credit, {days} days", { days: p.credit_days ?? 0 }) : t("Cash on delivery")],
              ["Planned value", money(p.estimated_total)],
              ["Received value", money(p.received_total)],
              ["Made by", p.creator?.name],
            ]} />
            {p.notes && <p className="mt-3 whitespace-pre-line rounded-md bg-ink-50 px-3 py-2 text-sm">{p.notes}</p>}
            <div className="mt-4 flex flex-col gap-2">
              {can(["purchase_plans.cancel", "purchase_plans.receive"]) && ["received", "partially_received"].includes(p.status) && <Button variant="secondary" icon={<Lock className="size-4" />} onClick={() => setDialog("close")}>{p.status === "partially_received" ? t("Close (the rest will not come)") : t("Close")}</Button>}
              {can("purchase_plans.cancel") && ["draft", "pending", "partially_received"].includes(p.status) && <Button variant="ghost" className="text-ember-600" icon={<Ban className="size-4" />} onClick={() => setDialog("cancel")}>{t("Cancel plan")}</Button>}
              {can("purchase_plans.create") && p.status === "draft" && <Button variant="ghost" className="text-ember-600" icon={<Trash2 className="size-4" />} onClick={() => setDialog("delete")}>{t("Delete draft")}</Button>}
            </div>
          </Panel>
          <Panel title={t("History")}>
            <ol className="relative space-y-3 border-l border-ink-200 pl-4">
              {(p.events ?? []).slice().reverse().map((ev) => (
                <li key={ev.id} className="text-sm">
                  <span className="absolute -left-[5px] mt-1.5 size-2.5 rounded-full bg-brand-500 ring-2 ring-surface" aria-hidden />
                  <p className="font-medium text-ink-900">{t(PLAN_EVENT_LABELS[ev.event] ?? ev.event)}</p>
                  {ev.note && <p className="text-ink-600">{ev.note}</p>}
                  <p className="text-xs text-ink-500">{dateTime(ev.created_at)}{ev.user ? ` · ${ev.user.name}` : ""}</p>
                </li>
              ))}
            </ol>
          </Panel>
        </div>
      </div>

      <ConfirmDialog open={dialog === "submit"} onClose={close} tone="primary" title={t("Approve {number}?", { number: p.number })}
        message={p.payment_type === "credit" ? t("It becomes pending: ordered and waiting for goods. Goods received will be on credit for {days} days.", { days: p.credit_days ?? 0 }) : t("It becomes pending: ordered and waiting for goods.")}
        confirmLabel={t("Approve")} loading={act.isPending} onConfirm={() => act.mutate({ action: "submit" })} />
      <ConfirmDialog open={dialog === "cancel"} onClose={close} title={t("Cancel {number}?", { number: p.number })} message={t("Goods already received stay in stock.")} confirmLabel={t("Cancel plan")} requireReason loading={act.isPending} onConfirm={(reason) => act.mutate({ action: "cancel", body: { reason } })} />
      <ConfirmDialog open={dialog === "close"} onClose={close} tone="primary" title={t("Close {number}?", { number: p.number })} message={t("No more goods will be received on this plan.")} confirmLabel={t("Close")} loading={act.isPending} onConfirm={() => act.mutate({ action: "close" })} />
      <ConfirmDialog open={dialog === "delete"} onClose={close} title={t("Delete this draft?")} message={p.number} confirmLabel={t("Delete")} loading={remove.isPending} onConfirm={() => remove.mutate(undefined)} />
      {dialog === "receive" && <ReceiveDialog plan={p} onClose={close} onDone={() => { close(); q.refetch(); }} />}
    </Guard>
  );
}

function ReceiveDialog({ plan, onClose, onDone }: { plan: PurchasePlan; onClose: () => void; onDone: () => void }) {
  const t = useT();
  const open = (plan.items ?? []).filter((i) => i.remaining_quantity > 0);
  const [lines, setLines] = useState(() => Object.fromEntries(open.map((i) => [i.id, { quantity: String(i.remaining_quantity), unit_cost: String(toNumber(i.unit_cost)), batch_no: "", expiry_date: "" }])));
  const credit = plan.payment_type === "credit";
  const [f, setF] = useState({ purchase_date: today(), supplier_invoice_no: "", amount_paid: credit ? "0" : "", payment_method: "cash", due_date: "", remind_on: "", transport_cost: "", notes: "" });
  const [channels, setChannels] = useState<Channel[]>((plan.reminder_channels ?? []) as Channel[]);
  const goods = open.reduce((s, i) => s + toNumber(lines[i.id]?.quantity) * toNumber(lines[i.id]?.unit_cost), 0);
  const receive = useAction(() => api.post(`admin/purchase-plans/${plan.id}/receive`, {
    purchase_date: f.purchase_date, supplier_invoice_no: f.supplier_invoice_no || null, transport_cost: toNumber(f.transport_cost),
    amount_paid: f.amount_paid === "" ? null : toNumber(f.amount_paid), payment_method: f.payment_method, notes: f.notes || null,
    due_date: f.due_date || null, remind_on: f.remind_on || null, reminder_channels: channels.length ? channels : null,
    items: open.map((i) => ({ plan_item_id: i.id, quantity: toNumber(lines[i.id].quantity), unit_cost: toNumber(lines[i.id].unit_cost), batch_no: lines[i.id].batch_no || null, expiry_date: lines[i.id].expiry_date || null })),
  }), { invalidate: [["admin/purchase-plans"], ["admin/purchases"]], onSuccess: onDone });
  const e = fieldErrors(receive.error);
  const owed = Math.max(0, goods - (f.amount_paid === "" ? goods : toNumber(f.amount_paid)));
  const set = (id: number, k: string, v: string) => setLines({ ...lines, [id]: { ...lines[id], [k]: v } });

  return (
    <Modal open onClose={onClose} size="xl" title={t("Receive goods for {number}", { number: plan.number })} description={t("Enter what actually arrived. It is added to stock as a purchase linked to this plan.")}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<CheckCircle2 className="size-4" />} loading={receive.isPending} onClick={() => receive.mutate(undefined)}>{t("Receive and add to stock")}</Button></>}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-sm">
          <thead><tr className="text-left text-xs text-ink-500"><th className="py-1">{t("Product")}</th><th className="py-1">{t("Received now")}</th><th className="py-1">{t("Cost")}</th><th className="py-1">{t("Batch")}</th><th className="py-1">{t("Expiry date")}</th></tr></thead>
          <tbody>
            {open.map((i) => (
              <tr key={i.id} className="border-t border-ink-100">
                <td className="py-1.5 pr-2">{i.product?.name}<span className="block text-xs text-ink-500">{t("{count} still to come", { count: qty(i.remaining_quantity) })}</span></td>
                <td className="py-1.5 pr-2"><Input type="number" min={0} max={i.remaining_quantity} step="any" value={lines[i.id].quantity} onChange={(ev) => set(i.id, "quantity", ev.target.value)} className="h-9 w-24" aria-label={t("Received now")} /></td>
                <td className="py-1.5 pr-2"><Input type="number" min={0} step="any" value={lines[i.id].unit_cost} onChange={(ev) => set(i.id, "unit_cost", ev.target.value)} className="h-9 w-28" aria-label={t("Cost")} /></td>
                <td className="py-1.5 pr-2"><Input value={lines[i.id].batch_no} onChange={(ev) => set(i.id, "batch_no", ev.target.value)} className="h-9 w-24" aria-label={t("Batch")} /></td>
                <td className="py-1.5"><Input type="date" required={i.product?.track_expiry} value={lines[i.id].expiry_date} onChange={(ev) => set(i.id, "expiry_date", ev.target.value)} className="h-9" aria-label={t("Expiry date")} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {Object.entries(e).filter(([k]) => k.startsWith("items")).slice(0, 2).map(([k, v]) => <p key={k} className="mt-1 text-xs text-ember-600">{v}</p>)}
      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <Field label={t("Date received")} error={e.purchase_date}>{(p) => <Input {...p} type="date" max={today()} value={f.purchase_date} onChange={(ev) => setF({ ...f, purchase_date: ev.target.value })} />}</Field>
        <Field label={t("Their invoice no.")}>{(p) => <Input {...p} value={f.supplier_invoice_no} onChange={(ev) => setF({ ...f, supplier_invoice_no: ev.target.value })} />}</Field>
        <Field label={t("Transport cost")}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.transport_cost} onChange={(ev) => setF({ ...f, transport_cost: ev.target.value })} />}</Field>
        <Field label={t("Paid to supplier now")} hint={credit ? t("Credit plan: usually 0") : t("Empty = paid in full")} error={e.amount_paid}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.amount_paid} placeholder={money(goods)} onChange={(ev) => setF({ ...f, amount_paid: ev.target.value })} />}</Field>
        <Field label={t("Paid by")}>{(p) => <Select {...p} value={f.payment_method} onChange={(ev) => setF({ ...f, payment_method: ev.target.value })}><option value="cash">{t("Cash")}</option><option value="bank">{t("Bank")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="cheque">{t("Cheque")}</option><option value="lipa_namba">{t("Lipa namba")}</option></Select>}</Field>
        <div className="rounded-md bg-ink-50 p-2 text-sm"><p className="text-ink-600">{t("Goods value")}</p><p className="num text-lg font-semibold">{money(goods)}</p></div>
      </div>
      {owed > 0 && (
        <div className="mt-4 rounded-lg bg-saffron-50 p-3 ring-1 ring-saffron-100">
          <p className="text-sm font-medium text-saffron-800">{t("Owed to the supplier: {amount}", { amount: money(owed) })}</p>
          <div className="mt-2 grid gap-3 sm:grid-cols-2">
            <Field label={t("Pay by")} hint={credit && plan.credit_days !== null ? t("Empty = {days} days after receiving", { days: plan.credit_days }) : undefined} error={e.due_date}>{(p) => <Input {...p} type="date" min={f.purchase_date} value={f.due_date} onChange={(ev) => setF({ ...f, due_date: ev.target.value })} />}</Field>
            <Field label={t("Remind us on")} hint={t("Empty: a few days before (Settings)")}>{(p) => <Input {...p} type="date" value={f.remind_on} onChange={(ev) => setF({ ...f, remind_on: ev.target.value })} />}</Field>
          </div>
          <div className="mt-2"><ChannelPicker value={channels} onChange={setChannels} label={t("Remind by (empty = the usual ways)")} /></div>
        </div>
      )}
      <Field label={t("Notes")} className="mt-3">{(p) => <Textarea {...p} rows={2} value={f.notes} onChange={(ev) => setF({ ...f, notes: ev.target.value })} />}</Field>
    </Modal>
  );
}
