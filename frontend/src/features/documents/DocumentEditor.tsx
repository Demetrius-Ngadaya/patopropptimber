"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { ArrowLeft, Save, Trash2 } from "lucide-react";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { cn, money, today, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import type { Customer, Product, ProductUnit } from "@/lib/types";
import { Button, Field, Input, PageHeader, Panel, Select, Textarea } from "@/components/ui";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { CustomerPicker } from "@/features/crm/components/CustomerPicker";
import { TYPE_META, type DocType, type DocumentDetail, type SalesDocument } from "./types";

type Line = {
  key: string;
  product_id: number;
  name: string;
  units: ProductUnit[];
  base_unit: string | null;
  product_unit_id: number | null;
  quantity: string;
  /** Empty = the normal price for the customer. */
  unit_price: string;
  discount: string;
  description: string;
};
type Contact = { name: string; phone: string; email: string; address: string; tin: string; vrn: string };
type Preview = {
  lines: Array<{ product_id: number; description: string; unit_name: string | null; quantity: number; unit_price: number; discount: number; tax: number; tax_rate: number; line_total: number }>;
  subtotal: number; item_discount: number; discount: number; tax: number; total: number;
};

const blankContact: Contact = { name: "", phone: "", email: "", address: "", tin: "", vrn: "" };
let seq = 0;
const key = () => `l${++seq}`;

/** Create or change a quotation, proforma or invoice. Prices and totals come from the server. */
export function DocumentEditor({ type, existing }: { type: DocType; existing?: DocumentDetail }) {
  const t = useT();
  const router = useRouter();
  const meta = TYPE_META[type];

  const [customer, setCustomer] = useState<Customer | null>((existing?.customer as Customer | null | undefined) ?? null);
  const [contact, setContact] = useState<Contact>(existing ? {
    name: existing.customer_name ?? "", phone: existing.customer_phone ?? "", email: existing.customer_email ?? "",
    address: existing.customer_address ?? "", tin: existing.customer_tin ?? "", vrn: existing.customer_vrn ?? "",
  } : blankContact);
  const [lines, setLines] = useState<Line[]>(() => (existing?.items ?? []).map((i) => ({
    key: key(), product_id: i.product_id, name: i.product?.name ?? i.description, units: [], base_unit: i.unit_name,
    product_unit_id: i.product_unit_id, quantity: String(toNumber(i.quantity)), unit_price: String(toNumber(i.unit_price)),
    discount: toNumber(i.discount) ? String(toNumber(i.discount)) : "", description: i.description !== i.product?.name ? i.description : "",
  })));
  const [priceLevel, setPriceLevel] = useState(existing?.price_level ?? "");
  const [discount, setDiscount] = useState(existing && toNumber(existing.discount) ? String(toNumber(existing.discount)) : "");
  const [issueDate, setIssueDate] = useState(existing?.issue_date?.slice(0, 10) ?? today());
  const [dueDate, setDueDate] = useState(existing?.due_date?.slice(0, 10) ?? "");
  const [notes, setNotes] = useState(existing?.notes ?? "");
  // undefined = not touched: the server fills in the standard terms from Settings.
  const [terms, setTerms] = useState<string | undefined>(existing ? existing.terms ?? "" : undefined);

  const chooseCustomer = (c: Customer | null) => {
    setCustomer(c);
    if (c) {
      setContact({
        name: c.name, phone: c.phone ?? "", email: c.email ?? "", address: c.address ?? "",
        tin: String(c.tin ?? ""), vrn: String(c.vrn ?? ""),
      });
    }
  };

  const addProduct = (p: Product) => {
    const unitId = p.matched_unit_id ?? null;
    setLines((ls) => {
      const same = ls.find((l) => l.product_id === p.id && l.product_unit_id === unitId);
      if (same) return ls.map((l) => (l === same ? { ...l, quantity: String(toNumber(l.quantity) + 1) } : l));
      return [...ls, {
        key: key(), product_id: p.id, name: p.name, units: (p.units ?? []).filter((u) => u.is_active !== false), base_unit: p.unit?.short_name ?? null,
        product_unit_id: unitId, quantity: "1", unit_price: "", discount: "", description: "",
      }];
    });
  };
  const setLine = (k: string, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === k ? { ...l, ...patch } : l)));

  const payload = useMemo(() => ({
    type,
    customer_id: customer?.id ?? null,
    customer: { name: contact.name || null, phone: contact.phone || null, email: contact.email || null, address: contact.address || null, tin: contact.tin || null, vrn: contact.vrn || null },
    price_level: priceLevel || null,
    items: lines.filter((l) => toNumber(l.quantity) > 0).map((l) => ({
      product_id: l.product_id,
      product_unit_id: l.product_unit_id,
      quantity: toNumber(l.quantity),
      unit_price: l.unit_price === "" ? null : toNumber(l.unit_price),
      discount: toNumber(l.discount),
      description: l.description || null,
    })),
    discount: toNumber(discount),
    issue_date: issueDate || null,
    due_date: dueDate || null,
    notes: notes || null,
    ...(terms === undefined ? {} : { terms: terms || null }),
  }), [type, customer, contact, priceLevel, lines, discount, issueDate, dueDate, notes, terms]);

  // Live prices and totals, worked out by the server exactly as they will be saved.
  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const previewKey = useDebounced(JSON.stringify({ c: payload.customer_id, p: payload.price_level, i: payload.items, d: payload.discount }), 350);
  useEffect(() => {
    const body = JSON.parse(previewKey) as { c: number | null; p: string | null; i: unknown[]; d: number };
    if (!body.i.length) {
      setPreview(null);
      setPreviewError(null);
      return;
    }
    let live = true;
    api.post<ApiResponse<Preview>>("admin/documents/preview", { type, customer_id: body.c, price_level: body.p, items: body.i, discount: body.d })
      .then((r) => { if (live) { setPreview(r.data); setPreviewError(null); } })
      .catch((e) => { if (live) setPreviewError(e instanceof ApiError ? e.message : t("Could not work out the prices.")); });
    return () => { live = false; };
  }, [previewKey, type, t]);

  // Preview lines follow the lines that have a quantity, in order.
  const pricedFor = new Map<string, Preview["lines"][number]>();
  lines.filter((l) => toNumber(l.quantity) > 0).forEach((l, i) => { const p = preview?.lines[i]; if (p) pricedFor.set(l.key, p); });

  const save = useAction(
    () => (existing ? api.put<ApiResponse<SalesDocument>>(`admin/documents/${existing.id}`, payload) : api.post<ApiResponse<SalesDocument>>("admin/documents", payload)),
    { invalidate: [["admin/documents"]], onSuccess: (r) => router.push(`/admin/documents/${type}/${r.data.id}`) },
  );
  const errors = fieldErrors(save.error);
  const lineError = Object.entries(errors).find(([k]) => k.startsWith("items"))?.[1];
  const back = existing ? `/admin/documents/${type}/${existing.id}` : `/admin/documents/${type}`;

  return (
    <>
      <PageHeader
        back={<Link href={back} className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Back")}</Link>}
        title={existing ? t("Edit {number}", { number: existing.number }) : t(meta.new)}
        description={existing ? undefined : t(meta.intro)}
        actions={<Button icon={<Save className="size-4" />} loading={save.isPending} disabled={!payload.items.length} onClick={() => save.mutate(undefined)}>{existing ? t("Save changes") : t("Save as draft")}</Button>}
      />

      <div className="grid gap-5 xl:grid-cols-[1fr_360px]">
        <div className="space-y-5">
          <Panel title={t("Customer")}>
            <div className="space-y-4">
              <CustomerPicker value={customer} onChange={chooseCustomer} placeholder={t("Search a registered customer, or type the details below")} />
              {type === "invoice" && !customer && <p className="text-xs text-saffron-700">{t("An invoice can only be issued to a registered customer, because the amount becomes their debt.")}</p>}
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Field label={t("Name")} required error={errors["customer.name"]}>{(p) => <Input {...p} value={contact.name} onChange={(e) => setContact({ ...contact, name: e.target.value })} />}</Field>
                <Field label={t("Phone")} error={errors["customer.phone"]}>{(p) => <Input {...p} inputMode="tel" value={contact.phone} onChange={(e) => setContact({ ...contact, phone: e.target.value })} />}</Field>
                <Field label={t("Email")} error={errors["customer.email"]}>{(p) => <Input {...p} type="email" value={contact.email} onChange={(e) => setContact({ ...contact, email: e.target.value })} />}</Field>
                <Field label={t("Address")} error={errors["customer.address"]} className="lg:col-span-1">{(p) => <Input {...p} value={contact.address} onChange={(e) => setContact({ ...contact, address: e.target.value })} />}</Field>
                <Field label={t("Customer TIN")} error={errors["customer.tin"]}>{(p) => <Input {...p} value={contact.tin} onChange={(e) => setContact({ ...contact, tin: e.target.value })} />}</Field>
                <Field label={t("Customer VRN")} error={errors["customer.vrn"]}>{(p) => <Input {...p} value={contact.vrn} onChange={(e) => setContact({ ...contact, vrn: e.target.value })} />}</Field>
              </div>
            </div>
          </Panel>

          <Panel title={t("Products")} actions={
            <Select value={priceLevel} onChange={(e) => setPriceLevel(e.target.value)} className="h-9 w-auto" aria-label={t("Price level")}>
              <option value="">{t("Normal prices for this customer")}</option>
              <option value="retail">{t("Retail prices")}</option>
              <option value="wholesale">{t("Wholesale prices")}</option>
            </Select>
          }>
            <ProductPicker onSelect={addProduct} showStock placeholder="Search or scan a product to add" />
            {lineError && <p className="mt-2 text-sm text-ember-600">{lineError}</p>}
            {previewError && <p className="mt-2 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{previewError}</p>}
            {lines.length > 0 && (
              <div className="mt-4 overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm">
                  <thead>
                    <tr className="border-b border-ink-200 text-left text-[12.5px] text-ink-600">
                      <th className="py-2 pr-2 font-semibold">{t("Product")}</th>
                      <th className="w-24 px-2 py-2 font-semibold">{t("Quantity")}</th>
                      <th className="w-32 px-2 py-2 font-semibold">{t("Unit")}</th>
                      <th className="w-32 px-2 py-2 font-semibold">{t("Unit price")}</th>
                      <th className="w-28 px-2 py-2 font-semibold">{t("Discount")}</th>
                      <th className="w-28 px-2 py-2 text-right font-semibold">{t("Amount")}</th>
                      <th className="w-10" />
                    </tr>
                  </thead>
                  <tbody>
                    {lines.map((l) => {
                      const priced = pricedFor.get(l.key);
                      return (
                        <tr key={l.key} className="border-b border-ink-100 align-top">
                          <td className="py-2 pr-2">
                            <p className="font-medium text-ink-900">{l.name}</p>
                            <input value={l.description} onChange={(e) => setLine(l.key, { description: e.target.value })} placeholder={t("Own wording on the document (optional)")}
                              className="mt-1 w-full border-0 border-b border-dashed border-ink-200 bg-transparent px-0 py-0.5 text-xs text-ink-600 placeholder:text-ink-400 focus:border-brand-500 focus:outline-none" />
                          </td>
                          <td className="px-2 py-2"><Input type="number" min={0} step="any" value={l.quantity} onChange={(e) => setLine(l.key, { quantity: e.target.value })} className="h-9" aria-label={t("Quantity")} /></td>
                          <td className="px-2 py-2">
                            {l.units.length ? (
                              <Select value={l.product_unit_id ?? ""} onChange={(e) => setLine(l.key, { product_unit_id: e.target.value ? Number(e.target.value) : null, unit_price: "" })} className="h-9" aria-label={t("Unit")}>
                                <option value="">{l.base_unit ?? t("Piece")}</option>
                                {l.units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                              </Select>
                            ) : <span className="inline-block pt-2 text-ink-600">{priced?.unit_name ?? l.base_unit ?? "—"}</span>}
                          </td>
                          <td className="px-2 py-2"><Input type="number" min={0} step="any" value={l.unit_price} placeholder={priced ? String(priced.unit_price) : t("Normal price")} onChange={(e) => setLine(l.key, { unit_price: e.target.value })} className="h-9" aria-label={t("Unit price")} /></td>
                          <td className="px-2 py-2"><Input type="number" min={0} step="any" value={l.discount} placeholder="0" onChange={(e) => setLine(l.key, { discount: e.target.value })} className="h-9" aria-label={t("Discount")} /></td>
                          <td className="num px-2 py-2 pt-4 text-right font-medium">{priced ? money(priced.line_total) : "…"}</td>
                          <td className="py-2 pt-3"><button type="button" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))} className="rounded p-1 text-ink-400 hover:bg-ember-50 hover:text-ember-600" aria-label={t("Remove")}><Trash2 className="size-4" /></button></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
            {!lines.length && <p className="mt-4 rounded-md bg-ink-50 px-3 py-6 text-center text-sm text-ink-500">{t("Add the products this {kind} is for.", { kind: t(meta.one).toLowerCase() })}</p>}
          </Panel>

          <Panel title={t("Notes and terms")}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t("Notes for the customer")} error={errors.notes}>{(p) => <Textarea {...p} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
              <Field label={t("Terms")} hint={terms === undefined ? t("Leave as is to use the standard terms from Settings.") : undefined} error={errors.terms}>
                {(p) => <Textarea {...p} rows={3} value={terms ?? ""} placeholder={t("Standard terms from Settings")} onChange={(e) => setTerms(e.target.value)} />}
              </Field>
            </div>
          </Panel>
        </div>

        <div className="space-y-5">
          <Panel title={t("Dates")}>
            <div className="grid gap-3">
              <Field label={t("Date")} error={errors.issue_date}>{(p) => <Input {...p} type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} />}</Field>
              <Field label={t(meta.dueLabel)} hint={dueDate ? undefined : t("Empty: worked out from Settings.")} error={errors.due_date}>{(p) => <Input {...p} type="date" min={issueDate} value={dueDate} onChange={(e) => setDueDate(e.target.value)} />}</Field>
            </div>
          </Panel>
          <Panel title={t("Totals")}>
            <dl className="space-y-1.5 text-sm">
              <Row label={t("Subtotal")} value={preview ? money(preview.subtotal) : "—"} />
              {preview && preview.item_discount > 0 && <Row label={t("Line discounts")} value={`-${money(preview.item_discount)}`} />}
              <div className="flex items-center justify-between gap-3">
                <dt className="text-ink-600">{t("Extra discount")}</dt>
                <dd><Input type="number" min={0} step="any" value={discount} placeholder="0" onChange={(e) => setDiscount(e.target.value)} className="h-8 w-32 text-right" aria-label={t("Extra discount")} /></dd>
              </div>
              {preview && preview.tax > 0 && <Row label={t("VAT")} value={money(preview.tax)} />}
              <div className={cn("flex justify-between border-t border-ink-200 pt-2 text-base font-semibold", !preview && "text-ink-400")}>
                <dt>{t("Total")}</dt><dd className="num">{preview ? money(preview.total) : "—"}</dd>
              </div>
            </dl>
          </Panel>
        </div>
      </div>
    </>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return <div className="flex justify-between gap-3"><dt className="text-ink-600">{label}</dt><dd className="num text-ink-900">{value}</dd></div>;
}
