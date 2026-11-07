"use client";

import { useState } from "react";
import { Boxes, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, qty, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import type { Product, ProductUnit } from "@/lib/types";
import { Badge, Button, Checkbox, Field, Input, Modal, Panel } from "@/components/ui";

type Form = { name: string; factor: string; barcode: string; retail_price: string; wholesale_price: string; is_active: boolean };
const blank: Form = { name: "", factor: "", barcode: "", retail_price: "", wholesale_price: "", is_active: true };

/**
 * Packs of a product (carton of 24, dozen, bale ...). Wholesalers sell by the
 * pack and retailers by the piece; stock always counts pieces.
 */
export function ProductUnitsPanel({ product }: { product: Product }) {
  const t = useT();
  const { can } = useAuth();
  const base = product.unit?.short_name ?? t("pcs");
  const q = useApi<ApiResponse<ProductUnit[]>>(`admin/products/${product.id}/units`);
  const [editing, setEditing] = useState<ProductUnit | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<Form>(blank);
  const invalidate = [[`admin/products/${product.id}/units`], [`admin/products/${product.id}`]];

  const save = useAction(
    () => {
      const body = { ...form, factor: Number(form.factor), retail_price: Number(form.retail_price), wholesale_price: form.wholesale_price === "" ? null : Number(form.wholesale_price), barcode: form.barcode || null };
      return editing ? api.put(`admin/products/${product.id}/units/${editing.id}`, body) : api.post(`admin/products/${product.id}/units`, body);
    },
    { invalidate, onSuccess: () => setOpen(false) },
  );
  const remove = useAction((id: number) => api.del(`admin/products/${product.id}/units/${id}`), { invalidate });
  const restore = useAction((id: number) => api.post(`admin/products/${product.id}/units/${id}/restore`), { invalidate });
  const e = fieldErrors(save.error);

  const start = (u: ProductUnit | null) => {
    setEditing(u);
    setForm(u ? { name: u.name, factor: String(toNumber(u.factor)), barcode: u.barcode ?? "", retail_price: String(toNumber(u.retail_price)), wholesale_price: u.wholesale_price === null ? "" : String(toNumber(u.wholesale_price)), is_active: u.is_active !== false } : { ...blank, retail_price: "", factor: "" });
    setOpen(true);
  };

  const perPiece = toNumber(form.factor) > 0 && toNumber(form.retail_price) > 0 ? toNumber(form.retail_price) / toNumber(form.factor) : null;
  const units = q.data?.data ?? [];

  return (
    <Panel
      title={t("Packs & wholesale units")}
      actions={can("products.update") && <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => start(null)}>{t("Add pack")}</Button>}
    >
      {!units.length ? (
        <p className="flex items-start gap-2 text-sm text-ink-500"><Boxes className="mt-0.5 size-4 shrink-0" /> {t("Sold by the piece only. Add a pack (e.g. carton of 24) to sell or buy in bulk.")}</p>
      ) : (
        <ul className="divide-y divide-ink-100 text-sm">
          {units.map((u) => (
            <li key={u.id} className={`flex items-center justify-between gap-2 py-2 ${u.deleted_at ? "opacity-50" : ""}`}>
              <div className="min-w-0">
                <p className="font-medium text-ink-900">{u.name} <span className="font-normal text-ink-500">= {qty(u.factor)} {base}</span> {u.is_active === false && <Badge>{t("Hidden")}</Badge>}</p>
                <p className="num text-xs text-ink-500">
                  {t("Retail {price}", { price: money(u.retail_price) })}
                  {u.wholesale_price !== null && ` · ${t("Wholesale {price}", { price: money(u.wholesale_price) })}`}
                  {u.barcode && ` · ${u.barcode}`}
                </p>
              </div>
              {can("products.update") && (
                u.deleted_at
                  ? <Button size="sm" variant="ghost" aria-label={t("Restore")} onClick={() => restore.mutate(u.id)}><RotateCcw className="size-4" /></Button>
                  : <span className="flex shrink-0 gap-1">
                      <Button size="sm" variant="ghost" aria-label={t("Edit")} onClick={() => start(u)}><Pencil className="size-4" /></Button>
                      <Button size="sm" variant="ghost" aria-label={t("Delete")} onClick={() => confirm(t("Delete this pack?")) && remove.mutate(u.id)}><Trash2 className="size-4 text-ember-600" /></Button>
                    </span>
              )}
            </li>
          ))}
        </ul>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title={editing ? t("Edit pack") : t("Add a pack")} description={product.name}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)} disabled={!form.name || !form.factor || !form.retail_price}>{t("Save")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Name")} required error={e.name} hint={t("e.g. Carton (24), Dozen, Bale")}>{(p) => <Input {...p} value={form.name} onChange={(ev) => setForm({ ...form, name: ev.target.value })} />}</Field>
          <Field label={t("Pieces in one pack")} required error={e.factor} hint={t("How many {unit} are in one pack.", { unit: base })}>{(p) => <Input {...p} type="number" min={2} step="any" value={form.factor} onChange={(ev) => setForm({ ...form, factor: ev.target.value })} />}</Field>
          <Field label={t("Retail price of the pack")} required error={e.retail_price} hint={perPiece !== null ? t("{price} per piece", { price: money(perPiece) }) : undefined}>{(p) => <Input {...p} type="number" min={0} step="any" value={form.retail_price} onChange={(ev) => setForm({ ...form, retail_price: ev.target.value })} />}</Field>
          <Field label={t("Wholesale price of the pack")} error={e.wholesale_price}>{(p) => <Input {...p} type="number" min={0} step="any" value={form.wholesale_price} onChange={(ev) => setForm({ ...form, wholesale_price: ev.target.value })} />}</Field>
          <Field label={t("Pack barcode")} error={e.barcode} hint={t("Scanning it adds one pack to the sale.")} className="sm:col-span-2">{(p) => <Input {...p} value={form.barcode} onChange={(ev) => setForm({ ...form, barcode: ev.target.value })} />}</Field>
          <Checkbox label={t("Available for sale")} checked={form.is_active} onChange={(ev) => setForm({ ...form, is_active: ev.target.checked })} />
        </div>
      </Modal>
    </Panel>
  );
}
