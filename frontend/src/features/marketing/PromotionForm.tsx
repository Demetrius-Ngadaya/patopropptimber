"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { ArrowLeft, Save, X } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { cn, today } from "@/lib/format";
import { fieldErrors, useAction, useUrlParam } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { useLookups } from "@/lib/lookups";
import type { Customer } from "@/lib/types";
import { Button, Checkbox, Field, Input, PageHeader, Panel, Select, Switch, Textarea } from "@/components/ui";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { CustomerPicker } from "@/features/crm/components/CustomerPicker";
import { SEGMENT_KEYS, SEGMENTS, type Segment } from "./segments";
import type { Promotion, PromotionDetail } from "./types";

type Chip = { id: number; name: string };

const plusDays = (days: number) => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
};

/** Create or change a promotion: what is discounted, for whom, and when. */
export function PromotionForm({ existing }: { existing?: PromotionDetail }) {
  const t = useT();
  const router = useRouter();
  const { categories = [] } = useLookups(["categories"]);
  const [f, setF] = useState({
    name: existing?.name ?? "",
    description: existing?.description ?? "",
    type: existing?.type ?? "percent",
    value: existing ? String(Number(existing.value)) : "",
    applies_to: existing?.applies_to ?? "all",
    category_ids: existing?.category_ids ?? [],
    min_purchase: existing?.min_purchase ? String(Number(existing.min_purchase)) : "",
    max_discount: existing?.max_discount ? String(Number(existing.max_discount)) : "",
    audience: existing?.audience ?? "all",
    segments: (existing?.segments ?? []) as Segment[],
    starts_on: existing?.starts_on?.slice(0, 10) ?? today(),
    ends_on: existing?.ends_on?.slice(0, 10) ?? plusDays(14),
    uses_per_customer: existing?.uses_per_customer ? String(existing.uses_per_customer) : "",
    is_active: existing?.is_active ?? true,
  });
  const [products, setProducts] = useState<Chip[]>(existing?.products ?? []);
  const [customers, setCustomers] = useState<Chip[]>(existing?.customers ?? []);
  // Opened from Customer insights for a group: start with that group.
  useUrlParam("segment", (s) => setF((x) => ({ ...x, audience: "segments", segments: [s as Segment] })));

  const set = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => setF((x) => ({ ...x, [k]: v }));
  const toggle = <T,>(list: T[], v: T) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

  const save = useAction(() => {
    const body = {
      ...f,
      value: Number(f.value),
      min_purchase: f.min_purchase === "" ? null : Number(f.min_purchase),
      max_discount: f.max_discount === "" ? null : Number(f.max_discount),
      uses_per_customer: f.uses_per_customer === "" ? null : Number(f.uses_per_customer),
      category_ids: f.applies_to === "categories" ? f.category_ids : null,
      product_ids: f.applies_to === "products" ? products.map((p) => p.id) : null,
      segments: f.audience === "segments" ? f.segments : null,
      customer_ids: f.audience === "customers" ? customers.map((c) => c.id) : null,
    };
    return existing ? api.put<ApiResponse<Promotion>>(`admin/promotions/${existing.id}`, body) : api.post<ApiResponse<Promotion>>("admin/promotions", body);
  }, { invalidate: [["admin/promotions"], existing ? [`admin/promotions/${existing.id}`] : ["admin/promotions"]], onSuccess: (r) => router.push(`/admin/promotions/${r.data.id}`) });
  const e = fieldErrors(save.error);

  const chips = (list: Chip[], remove: (id: number) => void) => (
    <div className="mt-2 flex flex-wrap gap-1.5">
      {list.map((c) => (
        <span key={c.id} className="inline-flex items-center gap-1 rounded-full bg-brand-50 py-0.5 pl-2.5 pr-1 text-xs text-brand-800 ring-1 ring-brand-100">
          {c.name}
          <button type="button" onClick={() => remove(c.id)} className="rounded-full p-0.5 hover:bg-brand-100" aria-label={t("Remove")}><X className="size-3" /></button>
        </span>
      ))}
    </div>
  );

  return (
    <>
      <PageHeader
        back={<Link href={existing ? `/admin/promotions/${existing.id}` : "/admin/promotions"} className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Back")}</Link>}
        title={existing ? t("Edit {name}", { name: existing.name }) : t("New promotion")}
        actions={<Button icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button>}
      />
      <div className="grid gap-5 lg:grid-cols-2">
        <Panel title={t("The offer")}>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t("Name")} required error={e.name} className="sm:col-span-2">{(p) => <Input {...p} value={f.name} onChange={(ev) => set("name", ev.target.value)} placeholder={t("e.g. December special")} />}</Field>
            <Field label={t("Discount type")} error={e.type}>{(p) => <Select {...p} value={f.type} onChange={(ev) => set("type", ev.target.value as "percent" | "amount")}><option value="percent">{t("Percentage (%)")}</option><option value="amount">{t("Fixed amount")}</option></Select>}</Field>
            <Field label={f.type === "percent" ? t("Percentage off") : t("Amount off")} required error={e.value}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.value} onChange={(ev) => set("value", ev.target.value)} />}</Field>
            <Field label={t("Applies to")} error={e.applies_to} className="sm:col-span-2">
              {(p) => <Select {...p} value={f.applies_to} onChange={(ev) => set("applies_to", ev.target.value as typeof f.applies_to)}>
                <option value="all">{t("Everything")}</option><option value="categories">{t("Some categories")}</option><option value="products">{t("Some products")}</option>
              </Select>}
            </Field>
            {f.applies_to === "categories" && (
              <fieldset className="sm:col-span-2">
                <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Categories")}</legend>
                <div className="grid max-h-48 gap-1.5 overflow-y-auto rounded-md p-2 ring-1 ring-ink-200 sm:grid-cols-2">
                  {categories.map((c) => <Checkbox key={c.id} label={c.name} checked={f.category_ids.includes(c.id)} onChange={() => set("category_ids", toggle(f.category_ids, c.id))} />)}
                </div>
                {e.category_ids && <p className="mt-1 text-xs text-ember-600">{e.category_ids}</p>}
              </fieldset>
            )}
            {f.applies_to === "products" && (
              <div className="sm:col-span-2">
                <ProductPicker source="admin/products" showStock={false} placeholder="Search a product to add" onSelect={(p) => setProducts((l) => (l.some((x) => x.id === p.id) ? l : [...l, { id: p.id, name: p.name }]))} />
                {chips(products, (id) => setProducts((l) => l.filter((x) => x.id !== id)))}
                {e.product_ids && <p className="mt-1 text-xs text-ember-600">{e.product_ids}</p>}
              </div>
            )}
            <Field label={t("Minimum purchase")} hint={t("Optional")} error={e.min_purchase}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.min_purchase} onChange={(ev) => set("min_purchase", ev.target.value)} />}</Field>
            <Field label={t("Largest discount per sale")} hint={t("Optional")} error={e.max_discount}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.max_discount} onChange={(ev) => set("max_discount", ev.target.value)} />}</Field>
            <Field label={t("Description for customers")} error={e.description} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={f.description} onChange={(ev) => set("description", ev.target.value)} />}</Field>
          </div>
        </Panel>

        <div className="space-y-5">
          <Panel title={t("Who gets it")}>
            <div className="space-y-3">
              <div className="grid gap-2 sm:grid-cols-3">
                {([["all", "All customers"], ["segments", "Groups of customers"], ["customers", "Chosen customers"]] as const).map(([v, l]) => (
                  <button key={v} type="button" onClick={() => set("audience", v)} aria-pressed={f.audience === v}
                    className={cn("rounded-md px-3 py-2 text-sm ring-1", f.audience === v ? "bg-brand-50 font-medium text-brand-800 ring-brand-500" : "ring-ink-200 hover:bg-ink-50")}>{t(l)}</button>
                ))}
              </div>
              {f.audience === "all" && <p className="text-xs text-ink-500">{t("Anyone can get it at the till, including walk-in customers.")}</p>}
              {f.audience === "segments" && (
                <div className="grid gap-1.5 sm:grid-cols-2">
                  {SEGMENT_KEYS.map((s) => <Checkbox key={s} label={<span>{t(SEGMENTS[s].label)} <span className="text-xs text-ink-500">· {t(SEGMENTS[s].hint)}</span></span>} checked={f.segments.includes(s)} onChange={() => set("segments", toggle(f.segments, s))} />)}
                  {e.segments && <p className="text-xs text-ember-600 sm:col-span-2">{e.segments}</p>}
                </div>
              )}
              {f.audience === "customers" && (
                <div>
                  <CustomerPicker value={null} onChange={(c: Customer | null) => c && setCustomers((l) => (l.some((x) => x.id === c.id) ? l : [...l, { id: c.id, name: c.name }]))} />
                  {chips(customers, (id) => setCustomers((l) => l.filter((x) => x.id !== id)))}
                  {e.customer_ids && <p className="mt-1 text-xs text-ember-600">{e.customer_ids}</p>}
                </div>
              )}
              {f.audience !== "all" && <p className="text-xs text-ink-500">{t("Only these customers get the discount, and only when the sale is in their name.")}</p>}
            </div>
          </Panel>
          <Panel title={t("When")}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t("Starts")} required error={e.starts_on}>{(p) => <Input {...p} type="date" value={f.starts_on} onChange={(ev) => set("starts_on", ev.target.value)} />}</Field>
              <Field label={t("Ends")} required error={e.ends_on}>{(p) => <Input {...p} type="date" min={f.starts_on} value={f.ends_on} onChange={(ev) => set("ends_on", ev.target.value)} />}</Field>
              <Field label={t("Times each customer can use it")} hint={t("Empty = no limit")} error={e.uses_per_customer}>{(p) => <Input {...p} type="number" min={1} value={f.uses_per_customer} onChange={(ev) => set("uses_per_customer", ev.target.value)} />}</Field>
              <div className="pt-6"><Switch label={t("Active")} checked={f.is_active} onChange={(v) => set("is_active", v)} /></div>
            </div>
          </Panel>
        </div>
      </div>
    </>
  );
}
