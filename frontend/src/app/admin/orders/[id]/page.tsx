"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, CheckCircle2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { dateTime, date, money, qty, humanize, toNumber } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import type { Customer } from "@/lib/types";
import { Guard, Can } from "@/components/layout/Guard";
import { PaymentModal } from "@/features/sales/components/PaymentModal";
import { Badge, Button, ConfirmDialog, DataTable, DescriptionList, ErrorState, PageHeader, Panel, Spinner, StatusBadge } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { DeliveryBox } from "@/features/delivery/DeliveryBox";
import type { Delivery } from "@/features/delivery/types";
import { toLatLng } from "@/components/map";
import { ONLINE_STATUS_LABELS, ONLINE_STATUS_TONES, type OnlinePayment } from "@/features/payments/types";

type OrderDetail = {
  id: number; number: string; status: string; source: string; delivery_method: string; delivery_address: string | null; contact_phone: string;
  subtotal: string; delivery_fee: string; discount: string; total: string; preferred_date: string | null; customer_notes: string | null; staff_notes: string | null; created_at: string;
  customer: Customer; branch: { id: number; name: string };
  items: Array<{ id: number; product_name: string; quantity: string; unit_price: string; line_total: string }>;
  histories: Array<{ id: number; from_status: string | null; to_status: string; note: string | null; created_at: string; user?: { name: string } | null }>;
  sale?: { id: number; number: string } | null;
  delivery_latitude?: string | null;
  delivery_longitude?: string | null;
  delivery_distance_km?: string | null;
  delivery?: Delivery | null;
  paid_online: string;
  paid_online_at: string | null;
  online_payments?: OnlinePayment[];
};

const next: Record<string, Array<{ to: string; label: string }>> = {
  pending: [{ to: "confirmed", label: "Confirm order" }],
  confirmed: [{ to: "processing", label: "Start preparing" }, { to: "ready", label: "Mark ready" }],
  processing: [{ to: "ready", label: "Mark ready" }, { to: "out_for_delivery", label: "Send for delivery" }],
  ready: [{ to: "out_for_delivery", label: "Send for delivery" }],
  out_for_delivery: [],
};

export default function OrderDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const q = useApi<ApiResponse<OrderDetail>>(`admin/orders/${id}`);
  const [completing, setCompleting] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const inv = [[`admin/orders/${id}`], ["admin/orders"]];
  const move = useAction((v: { status: string; note?: string }) => api.post(`admin/orders/${id}/status`, v), { invalidate: inv, onSuccess: () => setCancelling(false) });
  const complete = useAction((v: object) => api.post(`admin/orders/${id}/complete`, v), { invalidate: inv, onSuccess: () => setCompleting(false) });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Order not found.")} />;
  const o = q.data.data;
  const open = !["completed", "cancelled"].includes(o.status);
  const paidOnline = toNumber(o.paid_online);
  // What is left to collect when the goods are handed over (money paid online counts automatically).
  const toCollect = Math.max(0, Number((toNumber(o.total) - paidOnline).toFixed(2)));

  return (
    <Guard perm="orders.view">
      <PageHeader
        back={<Link href="/admin/orders" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Orders")}</Link>}
        title={t("Order {number}", { number: o.number })}
        description={<span className="flex flex-wrap items-center gap-2">{dateTime(o.created_at)} <StatusBadge status={o.status} /> {o.source === "online" ? t("Placed online") : t("Taken by staff")}</span>}
        actions={open && (
          <>
            <Can perm="orders.update_status">
              {next[o.status]?.map((n) => <Button key={n.to} variant="secondary" loading={move.isPending && move.variables?.status === n.to} onClick={() => move.mutate({ status: n.to })}>{t(n.label)}</Button>)}
            </Can>
            <Can perm="orders.complete"><Button icon={<CheckCircle2 className="size-4" />} onClick={() => setCompleting(true)}>{t("Hand over & take payment")}</Button></Can>
            <Can perm="orders.cancel"><Button variant="danger" onClick={() => setCancelling(true)}>{t("Cancel order")}</Button></Can>
          </>
        )}
      />
      {(o.delivery_method === "delivery" || o.delivery) && (
        <DeliveryBox delivery={o.delivery} source={{ order_id: o.id }} canDeliver={open && o.status !== "pending"} address={o.delivery_address} phone={o.contact_phone}
          pin={toLatLng(o.delivery_latitude, o.delivery_longitude)} fee={toNumber(o.delivery_fee)} onChanged={() => q.refetch()} />
      )}
      {o.sale && <p className="mb-4 rounded-md bg-brand-50 px-4 py-2 text-sm text-brand-700">{t("Completed as sale")} <Link className="font-semibold underline" href={`/admin/sales/${o.sale.id}`}>{o.sale.number}</Link>.</p>}
      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <Panel title={t("Items")} padded={false}>
          <DataTable rows={o.items} rowKey={(r) => r.id} columns={[
            { key: "product_name", header: t("Product") },
            { key: "quantity", header: t("Qty"), align: "right", render: (r) => qty(r.quantity) },
            { key: "unit_price", header: t("Price"), align: "right", render: (r) => money(r.unit_price) },
            { key: "line_total", header: t("Amount"), align: "right", render: (r) => money(r.line_total) },
          ]} />
          <div className="space-y-1 border-t border-ink-100 px-4 py-3 text-sm">
            <div className="flex justify-between text-ink-600"><span>{t("Subtotal")}</span><span className="num">{money(o.subtotal)}</span></div>
            {toNumber(o.delivery_fee) > 0 && <div className="flex justify-between text-ink-600"><span>{t("Delivery fee")}</span><span className="num">{money(o.delivery_fee)}</span></div>}
            {toNumber(o.discount) > 0 && <div className="flex justify-between text-ink-600"><span>{t("Discount")}</span><span className="num">-{money(o.discount)}</span></div>}
            <div className="flex justify-between text-base font-semibold"><span>{t("Total")}</span><span className="num">{money(o.total)}</span></div>
            {paidOnline > 0 && (
              <>
                <div className="flex justify-between text-brand-700"><span>{t("Paid online")}</span><span className="num">-{money(paidOnline)}</span></div>
                {o.status !== "completed" && <div className="flex justify-between font-semibold"><span>{t("Still to collect")}</span><span className="num">{money(toCollect)}</span></div>}
              </>
            )}
          </div>
        </Panel>
        <div className="space-y-5">
          <Panel title={t("Customer & delivery")}>
            <DescriptionList items={[
              ["Customer", <Link key="c" href={`/admin/customers/${o.customer.id}`} className="text-brand-700 hover:underline">{o.customer.name}</Link>],
              ["Contact phone", o.contact_phone],
              ["Fulfilment", o.delivery_method === "delivery" ? "Delivery" : `Pickup at ${o.branch.name}`],
              ["Address", o.delivery_address],
              ...(o.delivery_distance_km ? [["Distance", t("About {km} km", { km: Number(o.delivery_distance_km) })] as [string, React.ReactNode]] : []),
              ["Wanted on", o.preferred_date ? date(o.preferred_date) : null],
              ["Customer note", o.customer_notes],
              ["Staff note", o.staff_notes],
            ]} />
          </Panel>
          {(o.online_payments?.length ?? 0) > 0 && (
            <Panel title={t("Payments by phone")}>
              {o.status === "cancelled" && paidOnline > 0 && (
                <p className="mb-3 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{t("This order was cancelled after the customer paid {amount} online. Send the money back to the customer.", { amount: money(paidOnline) })}</p>
              )}
              <ul className="space-y-2 text-sm">
                {o.online_payments!.map((p) => (
                  <li key={p.id} className="flex items-start justify-between gap-2">
                    <span className="min-w-0">
                      <span className="num font-medium">{money(p.amount)}</span> · {p.provider_name ?? p.phone}
                      <span className="block text-xs text-ink-500">{p.external_reference ?? p.number} · {dateTime(p.completed_at ?? p.created_at)}</span>
                      {p.failure_reason && p.status !== "completed" && <span className="block text-xs text-ink-500">{p.failure_reason}</span>}
                    </span>
                    <Badge tone={ONLINE_STATUS_TONES[p.status]}>{t(ONLINE_STATUS_LABELS[p.status])}</Badge>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
          <Panel title={t("History")}>
            <ol className="space-y-3">
              {o.histories.map((h) => (
                <li key={h.id} className="border-l-2 border-ink-200 pl-3 text-sm">
                  <p className="font-medium">{t(humanize(h.to_status))}</p>
                  <p className="text-xs text-ink-500">{dateTime(h.created_at)}{h.user && `, ${h.user.name}`}</p>
                  {h.note && <p className="text-ink-600">{h.note}</p>}
                </li>
              ))}
            </ol>
          </Panel>
        </div>
      </div>
      <PaymentModal open={completing && toCollect > 0} onClose={() => setCompleting(false)} total={toCollect} customer={o.customer} loading={complete.isPending} title={t("Complete order {number}", { number: o.number })} confirmLabel={t("Complete order")} tillFeatures={false} onConfirm={(d) => complete.mutate({ payments: d.payments, due_date: d.due_date, notes: d.notes })} />
      <ConfirmDialog open={completing && toCollect <= 0} onClose={() => setCompleting(false)} onConfirm={() => complete.mutate({ payments: [] })} loading={complete.isPending}
        title={t("Complete order {number}", { number: o.number })} message={t("The customer already paid the full amount online. Hand over the goods to complete the order.")} confirmLabel={t("Complete order")} tone="primary" />
      <ConfirmDialog open={cancelling} onClose={() => setCancelling(false)} onConfirm={(note) => move.mutate({ status: "cancelled", note })} loading={move.isPending} title={t("Cancel this order?")} message={paidOnline > 0 ? t("The customer already paid {amount} online. After cancelling, send the money back to them.", { amount: money(paidOnline) }) : t("The customer is notified by SMS if SMS updates are on.")} confirmLabel={t("Cancel order")} requireReason />
    </Guard>
  );
}
