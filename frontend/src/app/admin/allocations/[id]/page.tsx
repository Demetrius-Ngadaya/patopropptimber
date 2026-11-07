"use client";

import Link from "next/link";
import { use, useMemo, useState } from "react";
import { ArrowLeft, ClipboardCheck, Printer, ShoppingCart } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, dateTime, money, qty, toNumber } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Button, ConfirmDialog, DescriptionList, ErrorState, Field, Input, NumericInput, PageHeader, Panel, Select, Spinner, Stat, StatusBadge, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Allocation = {
  id: number; number: string; status: string; issued_at: string; reconciled_at: string | null; expected_amount: string; sold_amount: string; recorded_amount: string; recorded_sales: number; seller_id: number; amount_received: string; shortage: string; notes: string | null; reconcile_notes: string | null;
  seller: { name: string; phone?: string | null }; branch: { name: string }; creator?: { name: string } | null; sale?: { id: number; number: string } | null;
  items: Array<{ id: number; quantity_issued: string; quantity_returned: string; quantity_damaged: string; quantity_sold: string; quantity_recorded: string; unit_price: string; product: { name: string; unit?: { short_name: string } | null } }>;
};

export default function AllocationDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const { can, user } = useAuth();
  const q = useApi<ApiResponse<Allocation>>(`admin/allocations/${id}`);
  const [back, setBack] = useState<Record<number, { returned: string; damaged: string }>>({});
  const [money_, setMoney] = useState({ amount_received: "", payment_method: "cash", payment_reference: "", notes: "" });
  const [cancelling, setCancelling] = useState(false);
  const inv = [[`admin/allocations/${id}`], ["admin/allocations"]];
  const a = q.data?.data;

  const sold = useMemo(() => (a?.items ?? []).reduce((s, i) => {
    const r = toNumber(back[i.id]?.returned);
    const d = toNumber(back[i.id]?.damaged);
    return s + Math.max(0, toNumber(i.quantity_issued) - toNumber(i.quantity_recorded) - r - d) * toNumber(i.unit_price);
  }, 0), [a, back]);

  const reconcile = useAction(() => api.post(`admin/allocations/${id}/reconcile`, {
    items: (a?.items ?? []).map((i) => ({ allocation_item_id: i.id, quantity_returned: toNumber(back[i.id]?.returned), quantity_damaged: toNumber(back[i.id]?.damaged) })),
    amount_received: money_.amount_received === "" ? sold : toNumber(money_.amount_received),
    payment_method: money_.payment_method, payment_reference: money_.payment_reference || undefined, notes: money_.notes || undefined,
  }), { invalidate: inv });
  const cancel = useAction((reason: string) => api.post(`admin/allocations/${id}/cancel`, { reason }), { invalidate: inv, onSuccess: () => setCancelling(false) });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !a) return <ErrorState message={q.error?.message ?? t("Allocation not found.")} />;
  const open = a.status === "open";
  const canReconcile = open && can("allocations.reconcile");
  const received = money_.amount_received === "" ? sold : toNumber(money_.amount_received);
  const recorded = toNumber(a.recorded_amount);
  const canSell = open && can("allocations.sell") && (user?.id === a.seller_id || can("allocations.reconcile"));

  return (
    <Guard perm={["allocations.view", "allocations.view_own"]}>
      <PageHeader
        back={<Link href="/admin/allocations" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Allocations")}</Link>}
        title={t("Allocation {number}", { number: a.number })}
        description={<span className="flex items-center gap-2">{t("{name}, issued {issued_at}", { name: a.seller.name, issued_at: dateTime(a.issued_at) })} <StatusBadge status={a.status} /></span>}
        actions={open && can("allocations.cancel") && <Button variant="danger" onClick={() => setCancelling(true)}>{t("Cancel allocation")}</Button>}
      />
      {open && !canReconcile ? (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label={t("Value issued")} value={money(a.expected_amount)} />
          <Stat label={t("You have sold")} value={money(recorded)} sub={t("{count} sales", { count: String(a.recorded_sales) })} tone="brand" />
          <Stat label={t("Still to sell")} value={money(Math.max(0, toNumber(a.expected_amount) - recorded))} />
          <Stat label={t("Hand in when you return")} value={money(recorded)} sub={t("Plus the money for anything sold without a receipt")} />
        </div>
      ) : (
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={t("Value issued")} value={money(a.expected_amount)} />
        <Stat label={t("Sold")} value={money(open ? sold + recorded : a.sold_amount)} sub={recorded > 0 ? t("{amount} recorded by the seller ({count} sales)", { amount: money(recorded), count: String(a.recorded_sales) }) : open && canReconcile ? t("Based on what you enter below") : undefined} />
        <Stat label={t("Money received")} value={money(open ? received : a.amount_received)} tone="brand" />
        <Stat label={t("Shortage")} value={money(open ? Math.max(0, sold - received) : a.shortage)} tone={(open ? sold - received : toNumber(a.shortage)) > 0 ? "ember" : "default"} />
      </div>
      )}
      {canSell && <SellFromAllocation allocation={a} />}
      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <Panel title={canReconcile ? t("What came back?") : t("Items")} padded={false}>
          <table className="w-full text-sm">
            <thead><tr className="border-b border-ink-200 bg-ink-50/60 text-left text-[12.5px] text-ink-600"><th className="px-3 py-2">{t("Product")}</th><th className="px-3 py-2 text-right">{t("Issued")}</th><th className="px-3 py-2 text-right">{t("Sold by seller")}</th><th className="px-3 py-2 text-right">{t("Returned good")}</th><th className="px-3 py-2 text-right">{t("Damaged")}</th><th className="px-3 py-2 text-right">{t("Sold")}</th><th className="px-3 py-2 text-right">{t("Price")}</th></tr></thead>
            <tbody>
              {a.items.map((i) => {
                const r = back[i.id] ?? { returned: "", damaged: "" };
                const soldQty = open ? Math.max(0, toNumber(i.quantity_issued) - toNumber(r.returned) - toNumber(r.damaged)) : toNumber(i.quantity_sold);
                const left = toNumber(i.quantity_issued) - toNumber(i.quantity_recorded);
                return (
                  <tr key={i.id} className="border-b border-ink-100">
                    <td className="px-3 py-2 font-medium">{i.product.name}</td>
                    <td className="num px-3 py-2 text-right">{qty(i.quantity_issued, i.product.unit?.short_name)}</td>
                    <td className="num px-3 py-2 text-right">{qty(i.quantity_recorded)}{open && <span className="block text-xs text-ink-500">{t("{left} left", { left: qty(left) })}</span>}</td>
                    <td className="px-3 py-2 text-right">{canReconcile ? <Input aria-label={t("Returned {name}", { name: i.product.name })} type="number" min={0} step="any" className="num ml-auto h-9 w-20 text-right" value={r.returned} onChange={(e) => setBack({ ...back, [i.id]: { ...r, returned: e.target.value } })} /> : <span className="num">{qty(i.quantity_returned)}</span>}</td>
                    <td className="px-3 py-2 text-right">{canReconcile ? <Input aria-label={t("Damaged {name}", { name: i.product.name })} type="number" min={0} step="any" className="num ml-auto h-9 w-20 text-right" value={r.damaged} onChange={(e) => setBack({ ...back, [i.id]: { ...r, damaged: e.target.value } })} /> : <span className="num">{qty(i.quantity_damaged)}</span>}</td>
                    <td className="num px-3 py-2 text-right font-semibold">{qty(soldQty)}</td>
                    <td className="num px-3 py-2 text-right">{money(i.unit_price)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </Panel>
        {canReconcile ? (
          <Panel title={t("Money handed in")}>
            <div className="space-y-4">
              {recorded > 0 && <p className="rounded-md bg-brand-50 px-3 py-2 text-[12.5px] text-brand-800">{t("The seller already recorded {amount} in sales with receipts. Count only the money for what was sold without a receipt.", { amount: money(recorded) })}</p>}
              <Field label={t("Amount received")} hint={t("Expected {sold}", { sold: money(sold) })}>{(p) => <Input {...p} type="number" min={0} step="any" className="num text-lg" placeholder={money(sold)} value={money_.amount_received} onChange={(e) => setMoney({ ...money_, amount_received: e.target.value })} />}</Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label={t("Paid by")}>{(p) => <Select {...p} value={money_.payment_method} onChange={(e) => setMoney({ ...money_, payment_method: e.target.value })}><option value="cash">{t("Cash")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="bank">{t("Bank")}</option><option value="lipa_namba">{t("Lipa namba")}</option></Select>}</Field>
                <Field label={t("Reference")}>{(p) => <Input {...p} value={money_.payment_reference} onChange={(e) => setMoney({ ...money_, payment_reference: e.target.value })} />}</Field>
              </div>
              <Field label={t("Notes")}>{(p) => <Textarea {...p} rows={2} value={money_.notes} onChange={(e) => setMoney({ ...money_, notes: e.target.value })} />}</Field>
              <Button size="lg" className="w-full" icon={<ClipboardCheck className="size-5" />} loading={reconcile.isPending} disabled={received > sold + 0.004} onClick={() => reconcile.mutate(undefined)}>{t("Reconcile")}</Button>
              <p className="text-[12.5px] text-ink-500">{t("Good returns go back into stock. A sale is recorded for what was sold, and any money not handed in is recorded as the seller&apos;s shortage.")}</p>
            </div>
          </Panel>
        ) : (
          <Panel title={t("Details")}>
            <DescriptionList items={[["Branch", a.branch.name], ["Issued by", a.creator?.name], ["Reconciled", a.reconciled_at ? dateTime(a.reconciled_at) : null], ["Sale", a.sale ? <Link key="s" href={`/admin/sales/${a.sale.id}`} className="text-brand-700 hover:underline">{a.sale.number}</Link> : null], ["Notes", a.notes], ["Reconcile notes", a.reconcile_notes]]} />
          </Panel>
        )}
      </div>
      <ConfirmDialog open={cancelling} onClose={() => setCancelling(false)} onConfirm={(r) => cancel.mutate(r)} loading={cancel.isPending} title={t("Cancel this allocation?")} message={t("All issued stock goes back into the branch. Use this only if the seller never took the goods.")} confirmLabel={t("Cancel allocation")} requireReason />
    </Guard>
  );
}

type Line = { quantity: string };

/** The seller sells from their own allocation: pick quantities, take payment, print the receipt. */
function SellFromAllocation({ allocation: a }: { allocation: Allocation }) {
  const t = useT();
  const [lines, setLines] = useState<Record<number, Line>>({});
  const [buyer, setBuyer] = useState({ name: "", phone: "" });
  const [pay, setPay] = useState({ method: "cash", amount: "", reference: "" });
  const [last, setLast] = useState<{ id: number; number: string } | null>(null);
  const chosen = a.items.filter((i) => toNumber(lines[i.id]?.quantity) > 0);
  const total = Math.round(chosen.reduce((s, i) => s + toNumber(lines[i.id]?.quantity) * toNumber(i.unit_price), 0) * 100) / 100;
  const paid = pay.amount === "" ? total : toNumber(pay.amount);
  const tooMuch = chosen.some((i) => toNumber(lines[i.id]?.quantity) > toNumber(i.quantity_issued) - toNumber(i.quantity_recorded) + 0.0005);
  const needsRef = pay.method !== "cash";

  const sell = useAction(() => api.post<ApiResponse<{ id: number; number: string }>>(`admin/allocations/${a.id}/sales`, {
    items: chosen.map((i) => ({ allocation_item_id: i.id, quantity: toNumber(lines[i.id]?.quantity) })),
    customer_name: buyer.name || undefined, customer_phone: buyer.phone || undefined,
    payments: [{ method: pay.method, amount: paid, reference: pay.reference || undefined }],
  }), {
    invalidate: [[`admin/allocations/${a.id}`], ["admin/allocations"]],
    onSuccess: (res) => { setLast(res.data); setLines({}); setBuyer({ name: "", phone: "" }); setPay({ method: "cash", amount: "", reference: "" }); },
  });

  return (
    <Panel title={t("Sell from this allocation")} className="mb-5">
      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <div className="space-y-2">
          {a.items.map((i) => {
            const left = toNumber(i.quantity_issued) - toNumber(i.quantity_recorded);
            const v = lines[i.id]?.quantity ?? "";
            const over = toNumber(v) > left + 0.0005;
            return (
              <div key={i.id} className="flex flex-wrap items-center gap-3 rounded-md px-1 py-1.5">
                <div className="min-w-40 flex-1">
                  <p className="font-medium text-ink-900">{i.product.name}</p>
                  <p className="text-xs text-ink-500">{money(i.unit_price)} · {t("{left} left", { left: qty(left, i.product.unit?.short_name) })}</p>
                </div>
                <NumericInput aria-label={t("Quantity of {name}", { name: i.product.name })} min={0} step="any" disabled={left <= 0} placeholder="0"
                  className={cn("num h-10 w-24 rounded-md border px-2 text-right text-sm", over ? "border-ember-500 bg-ember-50" : "border-ink-200 bg-surface")}
                  value={v} onChange={(e) => setLines({ ...lines, [i.id]: { quantity: e.target.value } })} />
                <span className="num w-28 text-right text-sm font-semibold">{money(toNumber(v) * toNumber(i.unit_price))}</span>
              </div>
            );
          })}
        </div>
        <div className="space-y-3 rounded-lg bg-ink-50/70 p-4">
          <div className="flex items-baseline justify-between"><span className="text-sm text-ink-600">{t("Total")}</span><span className="num text-2xl font-semibold text-ink-950">{money(total)}</span></div>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t("Customer (optional)")}>{(p) => <Input {...p} value={buyer.name} onChange={(e) => setBuyer({ ...buyer, name: e.target.value })} />}</Field>
            <Field label={t("Phone")}>{(p) => <Input {...p} inputMode="tel" value={buyer.phone} onChange={(e) => setBuyer({ ...buyer, phone: e.target.value })} />}</Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t("Paid by")}>{(p) => <Select {...p} value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}><option value="cash">{t("Cash")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="lipa_namba">{t("Lipa namba")}</option><option value="bank">{t("Bank")}</option></Select>}</Field>
            <Field label={t("Amount received")}>{(p) => <Input {...p} type="number" min={0} step="any" className="num" placeholder={money(total)} value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} />}</Field>
          </div>
          {needsRef && <Field label={t("Transaction ID")} required>{(p) => <Input {...p} value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} />}</Field>}
          {pay.method === "cash" && paid > total && <p className="text-sm text-ink-700">{t("Change")}: <span className="num font-semibold">{money(paid - total)}</span></p>}
          <Button size="lg" className="w-full" icon={<ShoppingCart className="size-5" />} loading={sell.isPending}
            disabled={!chosen.length || tooMuch || paid + 0.004 < total || (needsRef && !pay.reference)} onClick={() => sell.mutate(undefined)}>
            {t("Complete sale")}
          </Button>
          {last && (
            <a href={`/receipt/${last.id}`} target="_blank" rel="noreferrer" className="flex items-center justify-center gap-1.5 text-sm font-medium text-brand-700 hover:underline">
              <Printer className="size-4" /> {t("Print receipt {number}", { number: last.number })}
            </a>
          )}
        </div>
      </div>
    </Panel>
  );
}

