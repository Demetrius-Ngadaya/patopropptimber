"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { fieldErrors, useAction } from "@/lib/hooks";
import type { Product } from "@/lib/types";
import { Button, Field, Input, Modal, Select, Textarea } from "@/components/ui";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { useT } from "@/lib/i18n";

const types = [
  { value: "count", label: "Stock count (set the quantity)", hint: "Enter what you physically counted. The difference is adjusted." },
  { value: "damage", label: "Damaged / broken", hint: "Removes the quantity from stock." },
  { value: "expired", label: "Expired / write-off", hint: "Removes the quantity from stock." },
  { value: "decrease", label: "Other decrease", hint: "Removes the quantity from stock." },
  { value: "increase", label: "Other increase (found stock)", hint: "Adds the quantity to stock." },
];

export function AdjustStockModal({ open, onClose, product: initial, onDone }: { open: boolean; onClose: () => void; product?: Product | null; onDone?: () => void }) {
  const tr = useT();
  const [product, setProduct] = useState<Product | null>(initial ?? null);
  const [form, setForm] = useState({ type: "count", quantity: "", reason: "", expiry_date: "", unit_cost: "" });
  const save = useAction(
    () => api.post("admin/stock/adjustments", { product_id: product?.id, type: form.type, quantity: Number(form.quantity), reason: form.reason, expiry_date: form.expiry_date || undefined, unit_cost: form.unit_cost ? Number(form.unit_cost) : undefined }),
    { invalidate: [["admin/stock/adjustments"], ["admin/products"], [`admin/products/${product?.id}`], ["admin/stock/alerts"]], onSuccess: () => { onDone?.(); onClose(); } },
  );
  const errors = fieldErrors(save.error);
  const type = types.find((t) => t.value === form.type)!;

  return (
    <Modal open={open} onClose={onClose} title={tr("Adjust stock")} description={tr("Every adjustment is recorded with your name and reason.")} size="md"
      footer={<><Button variant="secondary" onClick={onClose}>{tr("Cancel")}</Button><Button loading={save.isPending} disabled={!product || form.quantity === "" || form.reason.trim().length < 3} onClick={() => save.mutate(undefined)}>{tr("Save adjustment")}</Button></>}>
      <div className="space-y-4">
        {initial ? <p className="text-sm font-medium">{initial.name}</p> : (
          product ? <div className="flex items-center justify-between rounded-md bg-brand-50 px-3 py-2 text-sm"><span className="font-medium">{product.name}</span><button type="button" className="text-brand-700 underline" onClick={() => setProduct(null)}>{tr("Change")}</button></div> : <ProductPicker source="admin/products" onSelect={setProduct} placeholder={tr("Find the product")} />
        )}
        <Field label={tr("What happened?")} hint={tr(type.hint)}>
          {(p) => <Select {...p} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>{types.map((t) => <option key={t.value} value={t.value}>{tr(t.label)}</option>)}</Select>}
        </Field>
        <Field label={form.type === "count" ? tr("Counted quantity") : tr("Quantity")} required error={errors.quantity}>
          {(p) => <Input {...p} type="number" min={0} step="any" className="num text-lg" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />}
        </Field>
        {(form.type === "increase" || form.type === "count") && product?.track_expiry && (
          <Field label={tr("Expiry date of the added stock")} error={errors.expiry_date}>{(p) => <Input {...p} type="date" value={form.expiry_date} onChange={(e) => setForm({ ...form, expiry_date: e.target.value })} />}</Field>
        )}
        <Field label={tr("Reason")} required error={errors.reason}>{(p) => <Textarea {...p} rows={2} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} placeholder={tr("e.g. Monthly stock take, broken in store")} />}</Field>
      </div>
    </Modal>
  );
}
