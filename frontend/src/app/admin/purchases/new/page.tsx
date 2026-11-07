"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { ArrowLeft, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, today, toNumber } from "@/lib/format";
import { fieldErrors, useAction } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import type { Product } from "@/lib/types";
import { Guard } from "@/components/layout/Guard";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { Button, Field, Input, PageHeader, Panel, Select, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import type { Channel } from "@/features/messaging/channels";

type Line = { product: Product; quantity: string; unit_cost: string; selling_price: string; batch_no: string; expiry_date: string };
const costFields = [["transport_cost", "Transport"], ["loading_cost", "Loading"], ["unloading_cost", "Unloading"], ["tax_cost", "Tax / duty"], ["other_cost", "Other"]] as const;

export default function NewPurchasePage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const { suppliers = [] } = useLookups(["suppliers"]);
  const [lines, setLines] = useState<Line[]>([]);
  const [h, setH] = useState({ supplier_id: "", supplier_invoice_no: "", purchase_date: today(), cost_allocation: "value", amount_paid: "", payment_method: "cash", payment_reference: "", due_date: "", remind_on: "", notes: "" });
  const [remindBy, setRemindBy] = useState<Channel[]>([]);
  const [costs, setCosts] = useState<Record<string, string>>({ transport_cost: "", loading_cost: "", unloading_cost: "", tax_cost: "", other_cost: "" });

  const add = (p: Product) => {
    if (lines.some((l) => l.product.id === p.id)) return;
    setLines((ls) => [...ls, { product: p, quantity: "1", unit_cost: p.cost_price ?? "", selling_price: "", batch_no: "", expiry_date: "" }]);
  };
  const upd = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  const calc = useMemo(() => {
    const subtotal = lines.reduce((s, l) => s + toNumber(l.quantity) * toNumber(l.unit_cost), 0);
    const qtyTotal = lines.reduce((s, l) => s + toNumber(l.quantity), 0);
    const extras = Object.values(costs).reduce((s, v) => s + toNumber(v), 0);
    const landed = lines.map((l) => {
      const q = toNumber(l.quantity);
      const lineTotal = q * toNumber(l.unit_cost);
      const share = extras === 0 ? 0 : h.cost_allocation === "quantity" || subtotal === 0 ? extras * (q / (qtyTotal || 1)) : extras * (lineTotal / subtotal);
      return q > 0 ? toNumber(l.unit_cost) + share / q : 0;
    });
    return { subtotal, extras, landed };
  }, [lines, costs, h.cost_allocation]);

  const paid = h.amount_paid === "" ? calc.subtotal : toNumber(h.amount_paid);
  const balance = Math.max(0, calc.subtotal - paid);

  const save = useAction(
    () => api.post<ApiResponse<{ id: number }>>("admin/purchases", {
      ...h,
      supplier_id: h.supplier_id || undefined,
      amount_paid: paid,
      due_date: balance > 0 ? h.due_date || undefined : undefined,
      remind_on: balance > 0 ? h.remind_on || undefined : undefined,
      reminder_channels: balance > 0 && remindBy.length ? remindBy : undefined,
      ...Object.fromEntries(Object.entries(costs).map(([k, v]) => [k, toNumber(v)])),
      items: lines.map((l) => ({
        product_id: l.product.id,
        quantity: toNumber(l.quantity),
        unit_cost: toNumber(l.unit_cost),
        selling_price: l.selling_price === "" ? undefined : toNumber(l.selling_price),
        batch_no: l.batch_no || undefined,
        expiry_date: l.expiry_date || undefined,
      })),
    }),
    { invalidate: [["admin/purchases"], ["admin/products"]], onSuccess: (r) => router.push(`/admin/purchases/${r.data.id}`) },
  );
  const errors = fieldErrors(save.error);
  const canPrice = can("products.update_price");

  return (
    <Guard perm="purchases.create">
      <PageHeader back={<Link href="/admin/purchases" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Purchases")}</Link>} title={t("Receive goods")} description={t("Add what arrived. Transport and other costs are spread over the items to get the true (landed) cost used for profit.")} />
      <div className="grid gap-5 xl:grid-cols-[1fr_360px]">
        <div className="space-y-5">
          <Panel title={t("Items received")}>
            <ProductPicker source="admin/products" onSelect={add} placeholder={t("Scan or search the product received")} showStock={false} />
            {errors.items && <p className="mt-2 text-sm text-ember-600">{errors.items}</p>}
            <div className="mt-4 space-y-3">
              {lines.map((l, i) => (
                <div key={l.product.id} className="rounded-lg p-3 ring-1 ring-ink-200">
                  <div className="mb-3 flex items-center justify-between gap-2">
                    <p className="font-medium">{l.product.name}</p>
                    <Button size="sm" variant="ghost" aria-label={t("Remove {name}", { name: l.product.name })} onClick={() => setLines((ls) => ls.filter((_, x) => x !== i))}><Trash2 className="size-4 text-ember-600" /></Button>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Field label={t("Quantity{value}", { value: l.product.unit ? ` (${l.product.unit.short_name})` : "" })} error={errors[`items.${i}.quantity`]}>{(p) => <Input {...p} type="number" min={0} step="any" value={l.quantity} onChange={(e) => upd(i, { quantity: e.target.value })} />}</Field>
                    <Field label={t("Supplier price / unit")} error={errors[`items.${i}.unit_cost`]}>{(p) => <Input {...p} type="number" min={0} step="any" value={l.unit_cost} onChange={(e) => upd(i, { unit_cost: e.target.value })} />}</Field>
                    <Field label={t("Landed cost / unit")} hint={t("Calculated")}>{(p) => <Input {...p} readOnly tabIndex={-1} className="num bg-ink-50" value={money(calc.landed[i])} />}</Field>
                    {canPrice && <Field label={t("New selling price")} hint={t("Now {selling_price}", { selling_price: money(l.product.selling_price) })}>{(p) => <Input {...p} type="number" min={0} step="any" value={l.selling_price} onChange={(e) => upd(i, { selling_price: e.target.value })} />}</Field>}
                    {l.product.track_expiry && (
                      <>
                        <Field label={t("Expiry date")} required error={errors[`items.${i}.expiry_date`]}>{(p) => <Input {...p} type="date" value={l.expiry_date} onChange={(e) => upd(i, { expiry_date: e.target.value })} />}</Field>
                        <Field label={t("Batch no.")}>{(p) => <Input {...p} value={l.batch_no} onChange={(e) => upd(i, { batch_no: e.target.value })} />}</Field>
                      </>
                    )}
                  </div>
                </div>
              ))}
              {!lines.length && <p className="py-6 text-center text-sm text-ink-500">{t("No items yet. Scan or search the products that arrived.")}</p>}
            </div>
          </Panel>
          <Panel title={t("Extra costs for this delivery")}>
            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
              {costFields.map(([k, label]) => (
                <Field key={k} label={label}>{(p) => <Input {...p} type="number" min={0} step="any" value={costs[k]} onChange={(e) => setCosts({ ...costs, [k]: e.target.value })} />}</Field>
              ))}
              <Field label={t("Spread costs by")}>{(p) => <Select {...p} value={h.cost_allocation} onChange={(e) => setH({ ...h, cost_allocation: e.target.value })}><option value="value">{t("Item value")}</option><option value="quantity">{t("Quantity")}</option></Select>}</Field>
            </div>
          </Panel>
        </div>

        <div className="space-y-5 xl:sticky xl:top-20 xl:self-start">
          <Panel title={t("Supplier & payment")}>
            <div className="space-y-4">
              <Field label={t("Supplier")} error={errors.supplier_id}>{(p) => <Select {...p} value={h.supplier_id} onChange={(e) => setH({ ...h, supplier_id: e.target.value })}><option value="">{t("Cash purchase (no supplier)")}</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>}</Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label={t("Their invoice no.")}>{(p) => <Input {...p} value={h.supplier_invoice_no} onChange={(e) => setH({ ...h, supplier_invoice_no: e.target.value })} />}</Field>
                <Field label={t("Date received")} error={errors.purchase_date}>{(p) => <Input {...p} type="date" max={today()} value={h.purchase_date} onChange={(e) => setH({ ...h, purchase_date: e.target.value })} />}</Field>
              </div>
              <div className="space-y-1 rounded-lg bg-ink-50 p-3 text-sm">
                <div className="flex justify-between"><span>{t("Goods (owed to supplier)")}</span><span className="num font-medium">{money(calc.subtotal)}</span></div>
                <div className="flex justify-between text-ink-600"><span>{t("Extra costs")}</span><span className="num">{money(calc.extras)}</span></div>
                <div className="flex justify-between border-t border-ink-200 pt-1 font-semibold"><span>{t("Landed total")}</span><span className="num">{money(calc.subtotal + calc.extras)}</span></div>
              </div>
              <Field label={t("Paid to supplier now")} hint={t("Leave empty if paid in full.")} error={errors.amount_paid}>{(p) => <Input {...p} type="number" min={0} step="any" placeholder={money(calc.subtotal)} value={h.amount_paid} onChange={(e) => setH({ ...h, amount_paid: e.target.value })} />}</Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label={t("Paid by")}>{(p) => <Select {...p} value={h.payment_method} onChange={(e) => setH({ ...h, payment_method: e.target.value })}><option value="cash">{t("Cash")}</option><option value="bank">{t("Bank")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="cheque">{t("Cheque")}</option><option value="lipa_namba">{t("Lipa namba")}</option></Select>}</Field>
                <Field label={t("Reference")}>{(p) => <Input {...p} value={h.payment_reference} onChange={(e) => setH({ ...h, payment_reference: e.target.value })} />}</Field>
              </div>
              {balance > 0 && (
                <div className="rounded-lg bg-saffron-50 p-3 text-sm text-saffron-700">
                  <p className="font-medium">{t("On credit:")} <span className="num">{money(balance)}</span></p>
                  {!h.supplier_id && <p className="mt-1">{t("Choose the supplier for a purchase on credit.")}</p>}
                  {!can("purchases.credit") && <p className="mt-1">{t("You are not allowed to record purchases on credit.")}</p>}
                  <div className="mt-2 grid grid-cols-2 gap-2">
                    <Field label={t("Pay by")}>{(p) => <Input {...p} type="date" min={h.purchase_date} value={h.due_date} onChange={(e) => setH({ ...h, due_date: e.target.value })} />}</Field>
                    <Field label={t("Remind us on")} hint={t("Empty: a few days before (Settings)")}>{(p) => <Input {...p} type="date" min={h.purchase_date} max={h.due_date || undefined} value={h.remind_on} onChange={(e) => setH({ ...h, remind_on: e.target.value })} />}</Field>
                  </div>
                  <div className="mt-2"><ChannelPicker value={remindBy} onChange={setRemindBy} label={t("Remind by (empty = the usual ways)")} /></div>
                </div>
              )}
              <Field label={t("Notes")}>{(p) => <Textarea {...p} rows={2} value={h.notes} onChange={(e) => setH({ ...h, notes: e.target.value })} />}</Field>
              <Button size="lg" className="w-full" loading={save.isPending} disabled={!lines.length || (balance > 0 && (!h.supplier_id || !can("purchases.credit")))} onClick={() => save.mutate(undefined)}>
                {t("Save and add to stock")}
              </Button>
            </div>
          </Panel>
        </div>
      </div>
    </Guard>
  );
}
