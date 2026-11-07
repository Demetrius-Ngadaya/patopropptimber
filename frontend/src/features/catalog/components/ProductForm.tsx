"use client";

import { useEffect, useState } from "react";
import { Barcode as BarcodeIcon } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors, useAction } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import type { Product } from "@/lib/types";
import { money, toNumber } from "@/lib/format";
import { Button, Field, Input, Modal, Select, Switch, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { Barcode } from "./Barcode";

/** An in-store EAN-13 ("20" prefix, never used by manufacturers). The server rejects duplicates. */
function newEan13(): string {
  const body = "20" + Array.from({ length: 10 }, () => Math.floor(Math.random() * 10)).join("");
  const sum = body.split("").reduce((acc, d, i) => acc + Number(d) * (i % 2 === 0 ? 1 : 3), 0);
  return body + ((10 - (sum % 10)) % 10);
}

type FormState = {
  name: string; sku: string; barcode: string; category_id: string; unit_id: string; brand: string; description: string; description_sw: string; wholesale_min_qty: string;
  cost_price: string; selling_price: string; wholesale_price: string; min_selling_price: string; tax_rate: string; reorder_level: string;
  track_expiry: boolean; expiry_alert_days: string; allow_decimal: boolean; is_service: boolean; show_online: boolean; is_active: boolean;
  opening_quantity: string; opening_expiry_date: string; opening_batch_no: string; change_reason: string;
};

const blank: FormState = {
  name: "", sku: "", barcode: "", category_id: "", unit_id: "", brand: "", description: "", description_sw: "", wholesale_min_qty: "", cost_price: "", selling_price: "", wholesale_price: "", min_selling_price: "",
  tax_rate: "0", reorder_level: "0", track_expiry: false, expiry_alert_days: "", allow_decimal: false, is_service: false, show_online: false, is_active: true,
  opening_quantity: "", opening_expiry_date: "", opening_batch_no: "", change_reason: "",
};

export function ProductForm({ open, onClose, product, onSaved }: { open: boolean; onClose: () => void; product?: Product | null; onSaved?: (p: Product) => void }) {
  const t = useT();
  const barcodes = usePublicSettings().settings?.barcode_enabled !== false;
  const { can } = useAuth();
  const { categories = [], units = [] } = useLookups(["categories", "units"]);
  const [form, setForm] = useState<FormState>(blank);
  const [image, setImage] = useState<File | null>(null);
  const editing = Boolean(product);

  useEffect(() => {
    if (!open) return;
    setImage(null);
    if (product) {
      const v = (x: unknown) => (x === null || x === undefined ? "" : String(x));
      setForm({ ...blank, ...Object.fromEntries(Object.keys(blank).map((k) => [k, typeof blank[k as keyof FormState] === "boolean" ? Boolean((product as Record<string, unknown>)[k]) : v((product as Record<string, unknown>)[k])])), change_reason: "" } as FormState);
    } else {
      setForm(blank);
    }
  }, [open, product]);

  const save = useAction(
    () => {
      const fd = new FormData();
      Object.entries(form).forEach(([k, v]) => {
        if (editing && k.startsWith("opening_")) return;
        if (!editing && k === "change_reason") return;
        if (!can("products.view_cost") && (k === "cost_price" || k === "min_selling_price") && editing) return;
        fd.append(k, typeof v === "boolean" ? (v ? "1" : "0") : v);
      });
      if (image) fd.append("image", image);
      return editing ? api.putForm<ApiResponse<Product>>(`admin/products/${product!.id}`, fd) : api.post<ApiResponse<Product>>("admin/products", fd);
    },
    { invalidate: [["admin/products"], [`admin/products/${product?.id}`]], onSuccess: (r) => { onSaved?.(r.data); onClose(); } },
  );
  const errors = fieldErrors(save.error);
  const set = <K extends keyof FormState>(k: K, v: FormState[K]) => setForm((f) => ({ ...f, [k]: v }));
  const margin = toNumber(form.selling_price) - toNumber(form.cost_price);
  const marginPct = toNumber(form.cost_price) > 0 ? (margin / toNumber(form.cost_price)) * 100 : null;
  const canPrice = !editing || can("products.update_price");

  return (
    <Modal open={open} onClose={onClose} title={editing ? t("Edit {name}", { name: product?.name }) : t("New product")} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{editing ? t("Save changes") : t("Add product")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Product name")} required error={errors.name} className="sm:col-span-2">{(p) => <Input {...p} value={form.name} onChange={(e) => set("name", e.target.value)} />}</Field>
        <Field label={t("Category")} error={errors.category_id}>{(p) => <Select {...p} value={form.category_id} onChange={(e) => set("category_id", e.target.value)}><option value="">{t("None")}</option>{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select>}</Field>
        <Field label={t("Unit")} error={errors.unit_id}>
          {(p) => (
            <Select {...p} value={form.unit_id} onChange={(e) => { const u = units.find((x) => String(x.id) === e.target.value); setForm((f) => ({ ...f, unit_id: e.target.value, allow_decimal: u ? u.allow_decimal : f.allow_decimal })); }}>
              <option value="">{t("None")}</option>{units.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.short_name})</option>)}
            </Select>
          )}
        </Field>
        {barcodes && (
          <Field label={t("Barcode")} error={errors.barcode} hint={t("Scan the code on the packaging, press Generate, or leave empty to get one when saving.")}>{(p) => (
            <div>
              <div className="flex gap-2">
                <Input {...p} value={form.barcode} inputMode="numeric" onChange={(e) => set("barcode", e.target.value)} />
                <Button type="button" variant="secondary" icon={<BarcodeIcon className="size-4" />} onClick={() => set("barcode", newEan13())}>{t("Generate")}</Button>
              </div>
              {form.barcode.length >= 4 && <div className="keep-light mt-2 inline-flex rounded bg-white p-1.5 ring-1 ring-ink-100"><Barcode value={form.barcode} height={34} width={1.2} fontSize={10} /></div>}
            </div>
          )}</Field>
        )}
        <Field label={t("SKU / code")} error={errors.sku} hint={t("Leave empty to generate one.")}>{(p) => <Input {...p} value={form.sku} onChange={(e) => set("sku", e.target.value)} />}</Field>
        {(can("products.view_cost") || !editing) && (
          <Field label={t("Buying price")} required error={errors.cost_price}>{(p) => <Input {...p} type="number" min={0} step="any" disabled={!canPrice} value={form.cost_price} onChange={(e) => set("cost_price", e.target.value)} />}</Field>
        )}
        <Field label={t("Selling price")} required error={errors.selling_price} hint={form.cost_price && form.selling_price ? t("Profit {margin}{value} per unit", { margin: money(margin), value: marginPct !== null ? ` (${marginPct.toFixed(0)}%)` : "" }) : undefined}>
          {(p) => <Input {...p} type="number" min={0} step="any" disabled={!canPrice} value={form.selling_price} onChange={(e) => set("selling_price", e.target.value)} />}
        </Field>
        <Field label={t("Wholesale price")} error={errors.wholesale_price} hint={t("Charged to wholesale customers.")}>{(p) => <Input {...p} type="number" min={0} step="any" disabled={!canPrice} value={form.wholesale_price} onChange={(e) => set("wholesale_price", e.target.value)} />}</Field>
        <Field label={t("Wholesale from quantity")} error={errors.wholesale_min_qty} hint={t("Buying at least this many pieces gets the wholesale price automatically (if enabled in Settings).")}>{(p) => <Input {...p} type="number" min={0} step="any" disabled={!canPrice} value={form.wholesale_min_qty} onChange={(e) => set("wholesale_min_qty", e.target.value)} />}</Field>
        {(can("products.view_cost") || !editing) && (
          <Field label={t("Lowest allowed price")} error={errors.min_selling_price} hint={t("Cashiers cannot sell below this.")}>{(p) => <Input {...p} type="number" min={0} step="any" disabled={!canPrice} value={form.min_selling_price} onChange={(e) => set("min_selling_price", e.target.value)} />}</Field>
        )}
        <Field label={t("Warn when stock falls to")} error={errors.reorder_level}>{(p) => <Input {...p} type="number" min={0} step="any" value={form.reorder_level} onChange={(e) => set("reorder_level", e.target.value)} />}</Field>
        <Field label={t("Tax rate (%)")} error={errors.tax_rate}>{(p) => <Input {...p} type="number" min={0} max={100} step="any" value={form.tax_rate} onChange={(e) => set("tax_rate", e.target.value)} />}</Field>
        <Field label={t("Brand")}>{(p) => <Input {...p} value={form.brand} onChange={(e) => set("brand", e.target.value)} />}</Field>
        <Field label={t("Photo")} error={errors.image} hint={t("JPG, PNG or WebP up to 2 MB. Shown in the online shop.")}>{(p) => <Input {...p} type="file" accept="image/png,image/jpeg,image/webp" className="py-1.5" onChange={(e) => setImage(e.target.files?.[0] ?? null)} />}</Field>
        <Field label={t("Description (English)")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={form.description} onChange={(e) => set("description", e.target.value)} />}</Field>
        <Field label={t("Description (Kiswahili)")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={form.description_sw} onChange={(e) => set("description_sw", e.target.value)} />}</Field>

        <div className="space-y-3 rounded-lg bg-ink-50 p-3 sm:col-span-2">
          <Switch label={t("Sold by weight or volume")} description={t("Allows quantities like 0.75 kg. Set automatically from the unit.")} checked={form.allow_decimal} onChange={(v) => set("allow_decimal", v)} />
          <Switch label={t("Track batches and expiry dates")} description={t("For medicines and food. Expiry is asked when stock is received; oldest expiry sells first.")} checked={form.track_expiry} onChange={(v) => set("track_expiry", v)} />
          <Switch label={t("Service (no stock)")} description={t("For services or fees that are never counted in stock.")} checked={form.is_service} onChange={(v) => set("is_service", v)} />
          <Switch label={t("Show in the online shop")} checked={form.show_online} onChange={(v) => set("show_online", v)} />
          <Switch label={t("Active")} description={t("Inactive products cannot be sold.")} checked={form.is_active} onChange={(v) => set("is_active", v)} />
        </div>

        {!editing && !form.is_service && can("stock.opening") && (
          <div className="grid gap-4 rounded-lg ring-1 ring-ink-200 p-3 sm:col-span-2 sm:grid-cols-3">
            <p className="text-sm font-medium sm:col-span-3">{t("Opening stock in this branch (optional)")}</p>
            <Field label={t("Quantity")} error={errors.opening_quantity}>{(p) => <Input {...p} type="number" min={0} step="any" value={form.opening_quantity} onChange={(e) => set("opening_quantity", e.target.value)} />}</Field>
            {form.track_expiry && <Field label={t("Expiry date")} error={errors.opening_expiry_date}>{(p) => <Input {...p} type="date" value={form.opening_expiry_date} onChange={(e) => set("opening_expiry_date", e.target.value)} />}</Field>}
            {form.track_expiry && <Field label={t("Batch number")}>{(p) => <Input {...p} value={form.opening_batch_no} onChange={(e) => set("opening_batch_no", e.target.value)} />}</Field>}
          </div>
        )}

        {editing && (
          <Field label={t("Reason for this change")} required error={errors.change_reason} hint={t("Saved in the audit log with the before and after values.")} className="sm:col-span-2">
            {(p) => <Input {...p} value={form.change_reason} onChange={(e) => set("change_reason", e.target.value)} placeholder={t("e.g. Supplier increased price")} />}
          </Field>
        )}
      </div>
    </Modal>
  );
}
