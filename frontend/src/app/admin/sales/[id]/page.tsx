"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, Ban, Printer, Send, Undo2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, date, money, qty, toNumber } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { paymentMethodLabels } from "@/lib/lookups";
import type { Sale, SaleItem } from "@/lib/types";
import { Guard, Can } from "@/components/layout/Guard";
import { Button, ConfirmDialog, DataTable, DescriptionList, ErrorState, Field, Input, Modal, PageHeader, Panel, Select, Spinner, StatusBadge, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { SendDialog } from "@/features/messaging/SendDialog";
import { DeliveryBox } from "@/features/delivery/DeliveryBox";
import type { Delivery } from "@/features/delivery/types";

function ReturnModal({ sale, open, onClose }: { sale: Sale; open: boolean; onClose: () => void }) {
  const t = useT();
  const [lines, setLines] = useState<Record<number, { quantity: number; condition: string }>>({});
  const [reason, setReason] = useState("");
  const [refundMethod, setRefundMethod] = useState("cash");
  const submit = useAction(
    () =>
      api.post(`admin/sales/${sale.id}/returns`, {
        items: Object.entries(lines).filter(([, v]) => v.quantity > 0).map(([id, v]) => ({ sale_item_id: Number(id), quantity: v.quantity, condition: v.condition })),
        reason,
        refund_method: refundMethod,
      }),
    { invalidate: [[`admin/sales/${sale.id}`], ["admin/sales"]], onSuccess: onClose },
  );
  const value = (sale.items ?? []).reduce((s, i) => s + (lines[i.id]?.quantity ?? 0) * (toNumber(i.line_total) / toNumber(i.quantity)), 0);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t("Return items")}
      description={t("Good items go back into stock. Damaged items are recorded but not restocked.")}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button>
          <Button onClick={() => submit.mutate(undefined)} loading={submit.isPending} disabled={value <= 0 || reason.trim().length < 3}>
            {t("Record return of")} <span className="num">{money(value)}</span>
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-ink-200 text-left text-[12.5px] text-ink-500">
              <th className="py-2">{t("Item")}</th>
              <th className="py-2 text-right">{t("Can return")}</th>
              <th className="py-2 text-right">{t("Return qty")}</th>
              <th className="py-2">{t("Condition")}</th>
            </tr>
          </thead>
          <tbody>
            {sale.items?.map((i) => {
              const max = toNumber(i.quantity) - toNumber(i.returned_quantity);
              const l = lines[i.id] ?? { quantity: 0, condition: "restock" };
              return (
                <tr key={i.id} className="border-b border-ink-100">
                  <td className="py-2">{i.product_name}</td>
                  <td className="num py-2 text-right">{qty(max)}</td>
                  <td className="py-2 text-right">
                    <Input type="number" min={0} max={max} step="any" disabled={max <= 0} aria-label={t("Return quantity for {product_name}", { product_name: i.product_name })} className="num ml-auto h-9 w-24 text-right" value={l.quantity || ""} onChange={(e) => setLines((s) => ({ ...s, [i.id]: { ...l, quantity: Math.min(max, Number(e.target.value) || 0) } }))} />
                  </td>
                  <td className="py-2 pl-2">
                    <Select aria-label={t("Condition")} className="h-9" value={l.condition} onChange={(e) => setLines((s) => ({ ...s, [i.id]: { ...l, condition: e.target.value } }))}>
                      <option value="restock">{t("Good, back to stock")}</option>
                      <option value="damaged">{t("Damaged / expired")}</option>
                    </Select>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Refund paid by")} hint={t("Any amount owed by the customer is reduced first.")}>
            {(p) => (
              <Select {...p} value={refundMethod} onChange={(e) => setRefundMethod(e.target.value)}>
                <option value="cash">{t("Cash")}</option>
                <option value="mobile_money">{t("Mobile money")}</option>
                <option value="bank">{t("Bank")}</option>
                <option value="credit_note">{t("Credit note")}</option>
              </Select>
            )}
          </Field>
          <Field label={t("Reason")} required>
            {(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}
          </Field>
        </div>
      </div>
    </Modal>
  );
}

export default function SaleDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const { can } = useAuth();
  const q = useApi<ApiResponse<Sale & { delivery?: Delivery | null }>>(`admin/sales/${id}`);
  const [returning, setReturning] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const cancel = useAction((reason: string) => api.post(`admin/sales/${id}/cancel`, { reason }), { invalidate: [[`admin/sales/${id}`], ["admin/sales"]], onSuccess: () => setCancelling(false) });
  const [sending, setSending] = useState(false);

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Sale not found.")} onRetry={() => q.refetch()} />;
  const s = q.data.data;
  const active = s.status === "completed";
  const showCost = can("sales.view_profit") && s.cost_total !== undefined;

  return (
    <Guard perm={["sales.view", "sales.reprint"]}>
      <PageHeader
        back={<Link href="/admin/sales" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Sales")}</Link>}
        title={t("Receipt {number}", { number: s.number })}
        description={<span className="flex flex-wrap items-center gap-2">{dateTime(s.sold_at)} <StatusBadge status={s.status === "cancelled" ? "cancelled" : s.payment_status} /></span>}
        actions={
          <>
            <Button variant="secondary" icon={<Printer className="size-4" />} onClick={() => window.open(`/receipt/${s.id}`, "_blank", "noopener,width=420,height=700")}>{t("Print")}</Button>
            <Can perm="sales.reprint"><Button variant="secondary" icon={<Send className="size-4" />} onClick={() => setSending(true)}>{t("Send receipt")}</Button></Can>
            {active && <Can perm="sales.return"><Button variant="secondary" icon={<Undo2 className="size-4" />} onClick={() => setReturning(true)}>{t("Return items")}</Button></Can>}
            {active && <Can perm="sales.cancel"><Button variant="danger" icon={<Ban className="size-4" />} onClick={() => setCancelling(true)}>{t("Cancel sale")}</Button></Can>}
          </>
        }
      />

      <DeliveryBox delivery={s.delivery} source={{ sale_id: s.id }} canDeliver={active} address={s.customer?.address ?? null} phone={s.customer?.phone ?? s.customer_phone} onChanged={() => q.refetch()} />
      {s.status === "cancelled" && <div className="mb-4"><ErrorState message={t("This sale was cancelled: {value}", { value: s.cancel_reason ?? "" })} /></div>}

      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <Panel title={t("Items")} padded={false}>
          <DataTable
            rows={s.items}
            rowKey={(r) => r.id}
            columns={[
              { key: "product_name", header: t("Product"), render: (r) => <span className="font-medium">{r.product_name}</span> },
              { key: "quantity", header: t("Qty"), align: "right", render: (r) => <>{qty(r.quantity, r.product?.unit?.short_name)}{toNumber(r.returned_quantity) > 0 && <span className="block text-xs text-ember-600">{t("{returned_quantity} returned", { returned_quantity: qty(r.returned_quantity) })}</span>}</> },
              { key: "unit_price", header: t("Price"), align: "right", render: (r) => money(r.unit_price) },
              { key: "discount", header: t("Discount"), align: "right", render: (r) => (toNumber(r.discount) ? money(r.discount) : "—") },
              { key: "line_total", header: t("Amount"), align: "right", render: (r) => <span className="font-semibold">{money(r.line_total)}</span> },
              ...(showCost ? [{ key: "unit_cost", header: t("Unit cost"), align: "right" as const, render: (r: SaleItem) => money(r.unit_cost) }] : []),
            ]}
          />
          <div className="space-y-1 border-t border-ink-100 px-4 py-3 text-sm">
            {[
              ["Subtotal", s.subtotal],
              ["Line discounts", toNumber(s.item_discount) ? `-${money(s.item_discount)}` : null],
              ["Invoice discount", toNumber(s.discount) ? `-${money(s.discount)}` : null],
              ["Delivery", toNumber(s.delivery_fee) ? s.delivery_fee : null],
              ["Tax included", toNumber(s.tax) ? s.tax : null],
              ["Returned", toNumber(s.returned_amount) ? `-${money(s.returned_amount)}` : null],
            ].filter(([, v]) => v !== null).map(([k, v]) => (
              <div key={k} className="flex justify-between text-ink-600"><span>{k}</span><span className="num">{typeof v === "string" && v.startsWith("-") ? v : money(v)}</span></div>
            ))}
            <div className="flex justify-between pt-1 text-base font-semibold"><span>{t("Total")}</span><span className="num">{money(s.total)}</span></div>
            {showCost && <div className="flex justify-between text-brand-700"><span>{t("Gross profit")}</span><span className="num">{money(toNumber(s.total) - toNumber(s.returned_amount) - toNumber(s.cost_total))}</span></div>}
          </div>
        </Panel>

        <div className="space-y-5">
          <Panel title={t("Details")}>
            <DescriptionList items={[
              ["Customer", s.customer ? <Link className="text-brand-700 hover:underline" href={`/admin/customers/${s.customer.id}`}>{s.customer.name}</Link> : s.customer_name ?? "Walk-in"],
              ["Phone", s.customer_phone],
              ["Cashier", s.cashier?.name],
              ["Branch", s.branch?.name],
              ["Channel", <span key="c" className="capitalize">{s.channel}</span>],
              ["Due date", s.due_date ? date(s.due_date) : null],
            ]} />
            {s.notes && <p className="mt-3 rounded-md bg-ink-50 px-3 py-2 text-sm text-ink-700">{s.notes}</p>}
          </Panel>
          <Panel title={t("Payments")}>
            <div className="mb-3 grid grid-cols-2 gap-2 text-sm">
              <div className="rounded-md bg-brand-50 px-3 py-2"><p className="text-brand-700">{t("Paid")}</p><p className="num font-semibold">{money(s.amount_paid)}</p></div>
              <div className="rounded-md bg-ink-50 px-3 py-2"><p className="text-ink-500">{t("Balance")}</p><p className={`num font-semibold ${toNumber(s.balance) > 0 ? "text-ember-600" : ""}`}>{money(s.balance)}</p></div>
            </div>
            <ul className="space-y-2 text-sm">
              {s.payments?.map((p) => (
                <li key={p.id} className="flex justify-between gap-2">
                  <span className="min-w-0">
                    <span className="block">{t(paymentMethodLabels[p.method] ?? p.method)}{p.provider && ` (${p.provider})`}{p.type === "refund" && t(" refund")}{p.type === "debt" && t(" debt payment")}</span>
                    <span className="block text-xs text-ink-500">{dateTime(p.paid_at)}{p.reference && t(", ref {reference}", { reference: p.reference })}</span>
                  </span>
                  <span className={`num shrink-0 font-medium ${toNumber(p.amount) < 0 ? "text-ember-600" : ""}`}>{money(p.amount)}</span>
                </li>
              ))}
            </ul>
            {toNumber(s.balance) > 0 && s.customer && (
              <Can perm="sales.receive_payment">
                <Link href={`/admin/customers/${s.customer.id}?pay=1`} className="mt-3 inline-block text-sm font-medium text-brand-700 hover:underline">{t("Receive payment from {name}", { name: s.customer.name })}</Link>
              </Can>
            )}
          </Panel>
          {!!s.returns?.length && (
            <Panel title={t("Returns")}>
              <ul className="space-y-2 text-sm">
                {s.returns.map((r) => (
                  <li key={r.id}>
                    <div className="flex justify-between"><span className="font-medium">{r.number}</span><span className="num">{money(r.total)}</span></div>
                    <p className="text-xs text-ink-500">{dateTime(r.created_at)}: {r.reason}</p>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </div>
      </div>

      <ReturnModal sale={s} open={returning} onClose={() => setReturning(false)} />
      <ConfirmDialog
        open={cancelling}
        onClose={() => setCancelling(false)}
        onConfirm={(reason) => cancel.mutate(reason)}
        loading={cancel.isPending}
        title={t("Cancel this sale?")}
        message={<>{t("All items go back into stock")}{toNumber(s.balance) > 0 ? t(", the customer's debt is cleared") : ""}{toNumber(s.amount_paid) > 0 ? t(" and a cash refund is recorded") : ""}{t(". This cannot be undone.")}</>}
        confirmLabel={t("Cancel sale")}
        requireReason
      />
      <SendDialog
        open={sending}
        onClose={() => setSending(false)}
        title={t("Send receipt {number}", { number: s.number })}
        description={t("The customer gets the receipt on the ways you tick.")}
        endpoint={`admin/sales/${s.id}/send-receipt`}
        phone={s.customer_phone ?? s.customer?.phone}
        email={s.customer?.email}
        editableContacts
        defaults={["sms", "whatsapp", "email"]}
      />
    </Guard>
  );
}
