"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { ArrowLeft, Save, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { money, toNumber } from "@/lib/format";
import { fieldErrors, useAction } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { useLookups } from "@/lib/lookups";
import type { Product } from "@/lib/types";
import { Button, Field, Input, PageHeader, Panel, Select, Textarea } from "@/components/ui";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import type { Channel } from "@/features/messaging/channels";
import type { PurchasePlan } from "./types";

type Line = { key: string; product_id: number; name: string; unit: string | null; quantity: string; unit_cost: string; note: string };
let seq = 0;

/** Create or change a purchase plan (only while draft or pending). */
export function PlanEditor({ existing }: { existing?: PurchasePlan }) {
  const t = useT();
  const router = useRouter();
  const { suppliers = [] } = useLookups(["suppliers"]);
  const [f, setF] = useState({
    supplier_id: existing?.supplier_id ? String(existing.supplier_id) : "", title: existing?.title ?? "", expected_date: existing?.expected_date?.slice(0, 10) ?? "",
    payment_type: existing?.payment_type ?? "cash", credit_days: existing?.credit_days !== null && existing?.credit_days !== undefined ? String(existing.credit_days) : "30",
    reminder_days_before: existing?.reminder_days_before !== null && existing?.reminder_days_before !== undefined ? String(existing.reminder_days_before) : "", notes: existing?.notes ?? "",
  });
  const [channels, setChannels] = useState<Channel[]>((existing?.reminder_channels ?? []) as Channel[]);
  const [lines, setLines] = useState<Line[]>(() => (existing?.items ?? []).map((i) => ({
    key: `l${++seq}`, product_id: i.product_id, name: i.product?.name ?? "", unit: i.product?.unit?.short_name ?? null,
    quantity: String(toNumber(i.quantity)), unit_cost: String(toNumber(i.unit_cost)), note: i.note ?? "",
  })));
  const add = (p: Product) => setLines((ls) => ls.some((l) => l.product_id === p.id) ? ls : [...ls, {
    key: `l${++seq}`, product_id: p.id, name: p.name, unit: p.unit?.short_name ?? null, quantity: "1", unit_cost: String(toNumber(p.cost_price)), note: "",
  }]);
  const set = (k: string, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === k ? { ...l, ...patch } : l)));
  const total = lines.reduce((s, l) => s + toNumber(l.quantity) * toNumber(l.unit_cost), 0);

  const save = useAction(() => {
    const body = {
      ...f, supplier_id: f.supplier_id ? Number(f.supplier_id) : null, title: f.title || null, expected_date: f.expected_date || null, notes: f.notes || null,
      credit_days: f.payment_type === "credit" ? Number(f.credit_days) : null,
      reminder_days_before: f.reminder_days_before === "" ? null : Number(f.reminder_days_before),
      reminder_channels: f.payment_type === "credit" && channels.length ? channels : null,
      items: lines.map((l) => ({ product_id: l.product_id, quantity: toNumber(l.quantity), unit_cost: toNumber(l.unit_cost), note: l.note || null })),
    };
    return existing ? api.put<ApiResponse<PurchasePlan>>(`admin/purchase-plans/${existing.id}`, body) : api.post<ApiResponse<PurchasePlan>>("admin/purchase-plans", body);
  }, { invalidate: [["admin/purchase-plans"]], onSuccess: (r) => router.push(`/admin/purchase-plans/${r.data.id}`) });
  const e = fieldErrors(save.error);
  const lineError = Object.entries(e).find(([k]) => k.startsWith("items"))?.[1];

  return (
    <>
      <PageHeader
        back={<Link href={existing ? `/admin/purchase-plans/${existing.id}` : "/admin/purchase-plans"} className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Back")}</Link>}
        title={existing ? t("Edit {number}", { number: existing.number }) : t("New purchase plan")}
        description={t("List what you plan to buy. Make it pending when it is approved and ordered, then receive the goods as they arrive.")}
        actions={<Button icon={<Save className="size-4" />} loading={save.isPending} disabled={!lines.length} onClick={() => save.mutate(undefined)}>{t("Save")}</Button>}
      />
      <div className="grid gap-5 xl:grid-cols-[1fr_360px]">
        <Panel title={t("Products")}>
          <ProductPicker source="admin/products" showStock onSelect={add} placeholder="Search or scan a product to add" />
          {lineError && <p className="mt-2 text-sm text-ember-600">{lineError}</p>}
          {lines.length > 0 ? (
            <div className="mt-4 overflow-x-auto">
              <table className="w-full min-w-[620px] text-sm">
                <thead><tr className="border-b border-ink-200 text-left text-[12.5px] text-ink-600">
                  <th className="py-2 pr-2 font-semibold">{t("Product")}</th><th className="w-28 px-2 py-2 font-semibold">{t("Quantity")}</th>
                  <th className="w-32 px-2 py-2 font-semibold">{t("Expected cost")}</th><th className="w-28 px-2 py-2 text-right font-semibold">{t("Amount")}</th><th className="w-10" />
                </tr></thead>
                <tbody>
                  {lines.map((l) => (
                    <tr key={l.key} className="border-b border-ink-100 align-top">
                      <td className="py-2 pr-2"><p className="font-medium">{l.name}</p>
                        <input value={l.note} onChange={(ev) => set(l.key, { note: ev.target.value })} placeholder={t("Note (optional)")} className="mt-1 w-full border-0 border-b border-dashed border-ink-200 bg-transparent px-0 py-0.5 text-xs focus:border-brand-500 focus:outline-none" /></td>
                      <td className="px-2 py-2"><Input type="number" min={0} step="any" value={l.quantity} onChange={(ev) => set(l.key, { quantity: ev.target.value })} className="h-9" aria-label={t("Quantity")} />{l.unit && <span className="text-xs text-ink-500">{l.unit}</span>}</td>
                      <td className="px-2 py-2"><Input type="number" min={0} step="any" value={l.unit_cost} onChange={(ev) => set(l.key, { unit_cost: ev.target.value })} className="h-9" aria-label={t("Expected cost")} /></td>
                      <td className="num px-2 py-2 pt-4 text-right">{money(toNumber(l.quantity) * toNumber(l.unit_cost))}</td>
                      <td className="py-2 pt-3"><button type="button" onClick={() => setLines(lines.filter((x) => x.key !== l.key))} className="rounded p-1 text-ink-400 hover:text-ember-600" aria-label={t("Remove")}><Trash2 className="size-4" /></button></td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr><td colSpan={3} className="py-2 text-right font-semibold">{t("Planned value")}</td><td className="num px-2 py-2 text-right font-semibold">{money(total)}</td><td /></tr></tfoot>
              </table>
            </div>
          ) : <p className="mt-4 rounded-md bg-ink-50 px-3 py-6 text-center text-sm text-ink-500">{t("Add the products you plan to buy.")}</p>}
        </Panel>
        <div className="space-y-5">
          <Panel title={t("Supplier and dates")}>
            <div className="space-y-3">
              <Field label={t("Supplier")} required={f.payment_type === "credit"} error={e.supplier_id}>{(p) => <Select {...p} value={f.supplier_id} onChange={(ev) => setF({ ...f, supplier_id: ev.target.value })}><option value="">{t("Not chosen yet")}</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>}</Field>
              <Field label={t("Title (optional)")} error={e.title}>{(p) => <Input {...p} value={f.title} onChange={(ev) => setF({ ...f, title: ev.target.value })} placeholder={t("e.g. Stock for December")} />}</Field>
              <Field label={t("Goods expected on")} error={e.expected_date}>{(p) => <Input {...p} type="date" value={f.expected_date} onChange={(ev) => setF({ ...f, expected_date: ev.target.value })} />}</Field>
            </div>
          </Panel>
          <Panel title={t("Payment")}>
            <div className="space-y-3">
              <Field label={t("How we pay")}>{(p) => <Select {...p} value={f.payment_type} onChange={(ev) => setF({ ...f, payment_type: ev.target.value as "cash" | "credit" })}><option value="cash">{t("Cash on delivery")}</option><option value="credit">{t("On credit (pay later)")}</option></Select>}</Field>
              {f.payment_type === "credit" && <>
                <div className="grid grid-cols-2 gap-3">
                  <Field label={t("Days to pay")} hint={t("After each delivery")} error={e.credit_days}>{(p) => <Input {...p} type="number" min={0} value={f.credit_days} onChange={(ev) => setF({ ...f, credit_days: ev.target.value })} />}</Field>
                  <Field label={t("Remind days before")} hint={t("Empty = Settings")} error={e.reminder_days_before}>{(p) => <Input {...p} type="number" min={0} value={f.reminder_days_before} onChange={(ev) => setF({ ...f, reminder_days_before: ev.target.value })} />}</Field>
                </div>
                <ChannelPicker value={channels} onChange={setChannels} label={t("Remind by (empty = the usual ways)")} />
              </>}
            </div>
          </Panel>
          <Panel title={t("Notes")}><Textarea rows={3} value={f.notes} onChange={(ev) => setF({ ...f, notes: ev.target.value })} aria-label={t("Notes")} /></Panel>
        </div>
      </div>
    </>
  );
}
