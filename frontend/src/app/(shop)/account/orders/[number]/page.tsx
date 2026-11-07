"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, Bike, Check, CircleX } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { cn, date, dateTime, humanize, money, qty } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import { Button, ConfirmDialog, ErrorState, Spinner, StatusBadge } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { OrderPayment } from "@/features/shop/components/OrderPayment";

type Order = {
  number: string; status: string; delivery_method: string; delivery_address: string | null; contact_phone: string; subtotal: string; delivery_fee: string; discount: string; total: string;
  preferred_date: string | null; customer_notes: string | null; cancel_reason: string | null; created_at: string;
  branch: { name: string; phone: string | null; address: string | null } | null;
  items: Array<{ id: number; product_name: string; quantity: string; unit_price: string; line_total: string; product?: { slug: string } | null }>;
  histories: Array<{ id: number; to_status: string; note: string | null; created_at: string }>;
  delivery?: { number: string; status: string; tracking_token: string } | null;
  paid_online: string;
};

const steps = ["pending", "confirmed", "ready", "completed"] as const;
const stepLabels: Record<string, string> = { pending: "Received", confirmed: "Confirmed", ready: "Ready", completed: "Done" };

export default function MyOrderPage({ params }: { params: Promise<{ number: string }> }) {
  const t = useT();
  const { number } = use(params);
  const { settings } = usePublicSettings();
  const q = useApi<ApiResponse<Order>>(`shop/account/orders/${encodeURIComponent(number)}`);
  const [cancelling, setCancelling] = useState(false);
  const cancel = useAction((reason?: string) => api.post(`shop/account/orders/${encodeURIComponent(number)}/cancel`, { reason }), { invalidate: [[`shop/account/orders/${encodeURIComponent(number)}`], ["shop/account/orders"]], onSuccess: () => setCancelling(false) });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error) return <ErrorState message={q.error.status === 404 ? t("We could not find this order.") : q.error.message} />;
  const o = q.data!.data;
  const cur = settings?.currency ?? "";
  const placed = typeof window !== "undefined" && new URLSearchParams(window.location.search).get("placed") === "1";
  const paidOnline = Number(o.paid_online ?? 0);
  const open = !["completed", "cancelled"].includes(o.status);
  // Map in-between statuses onto the 4-step tracker.
  const position = o.status === "processing" ? 1 : o.status === "out_for_delivery" ? 2 : steps.indexOf(o.status as (typeof steps)[number]);

  return (
    <>
      <Link href="/account/orders" className="mb-4 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("My orders")}</Link>
      {placed && o.status === "pending" && (
        <div className="mb-5 rounded-lg bg-brand-50 px-5 py-4 text-brand-800 ring-1 ring-brand-100">
          <p className="font-semibold">{t("Thank you, your order has been placed.")}</p>
          <p className="text-sm">{t("The shop will confirm it soon")}{settings?.business_phone ? t(". Questions? Call {business_phone}", { business_phone: settings.business_phone }) : ""}.</p>
        </div>
      )}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="num text-2xl font-semibold text-ink-950">{o.number}</h1>
          <p className="text-sm text-ink-500">{t("Placed {created_at}", { created_at: dateTime(o.created_at) })}</p>
        </div>
        <div className="flex items-center gap-2">
          <StatusBadge status={o.status} />
          {o.status === "pending" && paidOnline <= 0 && <Button variant="secondary" size="sm" icon={<CircleX className="size-4" />} onClick={() => setCancelling(true)}>{t("Cancel order")}</Button>}
        </div>
      </div>

      {o.delivery && !["cancelled", "failed"].includes(o.delivery.status) && (
        <Link href={`/t/${o.delivery.tracking_token}`} className="mt-5 flex items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-3 text-brand-800 ring-1 ring-brand-100 hover:bg-brand-100">
          <span className="flex items-center gap-2"><Bike className="size-5" /><span><span className="block font-semibold">{o.delivery.status === "received" ? t("Delivered and received") : t("Follow your delivery")}</span><span className="text-sm">{t("See where the delivery person is, chat with them and confirm when you receive it.")}</span></span></span>
          <span className="shrink-0 text-sm font-semibold underline">{t("Track")}</span>
        </Link>
      )}

      {o.status === "cancelled" ? (
        <p className="mt-5 rounded-lg bg-ember-50 px-4 py-3 text-sm text-ember-700">{t("This order was cancelled")}{o.cancel_reason ? `: ${o.cancel_reason}` : "."}</p>
      ) : (
        <ol className="mt-6 grid grid-cols-4 gap-2" aria-label={t("Order progress")}>
          {steps.map((s, i) => {
            const done = i <= position;
            const label = s === "ready" && o.status === "out_for_delivery" ? "On the way" : s === "ready" && o.delivery_method === "delivery" ? "Ready / on the way" : stepLabels[s];
            return (
              <li key={s} className="flex flex-col gap-2">
                <span className={cn("h-1.5 rounded-full", done ? "bg-brand-600" : "bg-ink-200")} />
                <span className={cn("flex items-center gap-1 text-xs sm:text-sm", done ? "font-medium text-ink-900" : "text-ink-500")}>{done && <Check className="size-3.5 text-brand-600" />}{label}</span>
              </li>
            );
          })}
        </ol>
      )}

      <div className="mt-8 grid gap-6 lg:grid-cols-[1fr_320px]">
        <section className="overflow-hidden rounded-lg bg-surface ring-1 ring-ink-200/70">
          <table className="w-full text-sm">
            <thead className="bg-ink-50 text-left text-ink-500"><tr><th className="px-4 py-2.5 font-medium">{t("Product")}</th><th className="px-4 py-2.5 text-right font-medium">{t("Qty")}</th><th className="px-4 py-2.5 text-right font-medium">{t("Amount")}</th></tr></thead>
            <tbody className="divide-y divide-ink-100">
              {o.items.map((i) => (
                <tr key={i.id}>
                  <td className="px-4 py-3">{i.product?.slug ? <Link href={`/products/${i.product.slug}`} className="hover:underline">{i.product_name}</Link> : i.product_name}<span className="num block text-xs text-ink-500">@ {money(i.unit_price)}</span></td>
                  <td className="num px-4 py-3 text-right">{qty(i.quantity)}</td>
                  <td className="num px-4 py-3 text-right">{money(i.line_total)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t border-ink-200 text-sm">
              <tr><td colSpan={2} className="px-4 pt-3 text-right text-ink-600">{t("Subtotal")}</td><td className="num px-4 pt-3 text-right">{money(o.subtotal)}</td></tr>
              {Number(o.delivery_fee) > 0 && <tr><td colSpan={2} className="px-4 pt-1 text-right text-ink-600">{t("Delivery")}</td><td className="num px-4 pt-1 text-right">{money(o.delivery_fee)}</td></tr>}
              {Number(o.discount) > 0 && <tr><td colSpan={2} className="px-4 pt-1 text-right text-ink-600">{t("Discount")}</td><td className="num px-4 pt-1 text-right">−{money(o.discount)}</td></tr>}
              <tr><td colSpan={2} className="px-4 py-3 text-right font-semibold">{paidOnline > 0 ? t("Total") : t("Total to pay")}</td><td className="num px-4 py-3 text-right text-base font-semibold">{cur} {money(o.total)}</td></tr>
              {paidOnline > 0 && (
                <>
                  <tr><td colSpan={2} className="px-4 text-right text-brand-700">{t("Paid online")}</td><td className="num px-4 text-right text-brand-700">−{money(paidOnline)}</td></tr>
                  <tr><td colSpan={2} className="px-4 py-3 text-right font-semibold">{t("Still to pay")}</td><td className="num px-4 py-3 text-right font-semibold">{cur} {money(Math.max(0, Number(o.total) - paidOnline))}</td></tr>
                </>
              )}
            </tfoot>
          </table>
        </section>
        <aside className="space-y-4 text-sm">
          {(open || paidOnline > 0) && <OrderPayment number={o.number} currency={cur} defaultPhone={o.contact_phone} onPaid={() => q.refetch()} />}
          <div className="rounded-lg bg-surface p-4 ring-1 ring-ink-200/70">
            <h2 className="mb-2 font-semibold">{o.delivery_method === "delivery" ? t("Delivery") : t("Collection")}</h2>
            {o.delivery_method === "delivery" ? <p>{o.delivery_address}</p> : <p>{o.branch?.name}{o.branch?.address ? `, ${o.branch.address}` : ""}</p>}
            <p className="mt-1 text-ink-600">{t("Phone: {contact_phone}", { contact_phone: o.contact_phone })}</p>
            {o.preferred_date && <p className="text-ink-600">{t("Preferred day: {preferred_date}", { preferred_date: date(o.preferred_date) })}</p>}
            {o.customer_notes && <p className="mt-2 text-ink-600">{t("Your notes: {customer_notes}", { customer_notes: o.customer_notes })}</p>}
            {Number(o.total) - paidOnline > 0.004 && <p className="mt-3 text-ink-500">{t("Pay")} {o.delivery_method === "delivery" ? t("on delivery") : t("when you collect")}.</p>}
          </div>
          {o.histories.length > 0 && (
            <div className="rounded-lg bg-surface p-4 ring-1 ring-ink-200/70">
              <h2 className="mb-2 font-semibold">{t("History")}</h2>
              <ul className="space-y-2">
                {o.histories.map((h) => <li key={h.id}><span className="font-medium">{humanize(h.to_status)}</span> <span className="text-ink-500">· {dateTime(h.created_at)}</span>{h.note && <span className="block text-ink-600">{h.note}</span>}</li>)}
              </ul>
            </div>
          )}
        </aside>
      </div>
      <ConfirmDialog open={cancelling} onClose={() => setCancelling(false)} onConfirm={(reason?: string) => cancel.mutate(reason)} loading={cancel.isPending} title={t("Cancel this order?")} message={t("The shop will not prepare it. You can order again any time.")} confirmLabel={t("Cancel order")} />
    </>
  );
}
