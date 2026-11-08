"use client";

import { useState } from "react";
import { Pencil } from "lucide-react";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, qty } from "@/lib/format";
import { fieldErrors, useAction } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Button, DataTable, Field, Input, Modal, Panel } from "@/components/ui";

type Prices = { cost_price?: number | null; selling_price: number | null; wholesale_price: number | null; min_selling_price?: number | null };
export type BranchPrice = {
  branch_id: number;
  branch_name: string;
  quantity: number;
  own: Prices;
  effective: Prices;
  has_own_price: boolean;
};

const blank = { cost_price: "", selling_price: "", wholesale_price: "", min_selling_price: "" };
type Form = typeof blank;

/**
 * Each branch's buying and selling price for a product, and what its stock is worth.
 * A branch without its own price uses the product's price.
 */
export function BranchPricesPanel({ productId, unit, rows }: { productId: number; unit?: string | null; rows: BranchPrice[] }) {
  const t = useT();
  const { can } = useAuth();
  const showCost = can("products.view_cost");
  const canEdit = can("products.update") && can("products.update_price");
  const [editing, setEditing] = useState<BranchPrice | null>(null);
  const [form, setForm] = useState<Form>(blank);
  const save = useAction(
    () => api.put(`admin/products/${productId}/branch-prices`, {
      branch_id: editing?.branch_id,
      ...Object.fromEntries(Object.entries(form).filter(([k]) => showCost || (k !== "cost_price" && k !== "min_selling_price")).map(([k, v]) => [k, v === "" ? null : Number(v)])),
    }),
    { invalidate: [[`admin/products/${productId}`], ["admin/products"]], onSuccess: () => setEditing(null) },
  );
  const errors = fieldErrors(save.error);
  const open = (b: BranchPrice) => {
    save.reset();
    const v = (n: number | null | undefined) => (n === null || n === undefined ? "" : String(n));
    setForm({ cost_price: v(b.own.cost_price), selling_price: v(b.own.selling_price), wholesale_price: v(b.own.wholesale_price), min_selling_price: v(b.own.min_selling_price) });
    setEditing(b);
  };
  const numeric = (v: string) => v.replace(/[^0-9.]/g, "");
  const own = (b: BranchPrice, k: keyof Prices) => b.own[k] !== null && b.own[k] !== undefined;

  return (
    <Panel title={t("Prices and stock value by branch")} padded={false}>
      <DataTable
        rows={rows}
        rowKey={(r) => r.branch_id}
        exportable={t("Prices by branch")}
        empty={{ title: t("No branches") }}
        columns={[
          { key: "branch_name", header: t("Branch"), render: (r) => <span className="font-medium">{r.branch_name}</span> },
          { key: "quantity", header: t("In stock"), align: "right", render: (r) => qty(r.quantity, unit) },
          ...(showCost ? [{
            key: "effective.cost_price", header: t("Buying price"), align: "right" as const, total: false as const,
            render: (r: BranchPrice) => <span className={own(r, "cost_price") ? "font-semibold text-brand-800" : "text-ink-500"}>{money(r.effective.cost_price)}</span>,
          }] : []),
          {
            key: "effective.selling_price", header: t("Selling price"), align: "right", total: false,
            render: (r) => <span className={own(r, "selling_price") ? "font-semibold text-brand-800" : "text-ink-500"}>{money(r.effective.selling_price)}</span>,
          },
          ...(showCost ? [{
            key: "_cost_value", header: t("Value at buying price"), align: "right" as const, total: "money" as const,
            value: (r: BranchPrice) => r.quantity * (r.effective.cost_price ?? 0),
            exportValue: (r: BranchPrice) => r.quantity * (r.effective.cost_price ?? 0),
            render: (r: BranchPrice) => money(r.quantity * (r.effective.cost_price ?? 0)),
          }] : []),
          {
            key: "_retail_value", header: t("Money after selling"), align: "right", total: "money",
            value: (r) => r.quantity * (r.effective.selling_price ?? 0),
            exportValue: (r) => r.quantity * (r.effective.selling_price ?? 0),
            render: (r) => money(r.quantity * (r.effective.selling_price ?? 0)),
          },
          ...(showCost ? [{
            key: "_profit", header: t("Expected profit"), align: "right" as const, total: "money" as const,
            value: (r: BranchPrice) => r.quantity * ((r.effective.selling_price ?? 0) - (r.effective.cost_price ?? 0)),
            exportValue: (r: BranchPrice) => r.quantity * ((r.effective.selling_price ?? 0) - (r.effective.cost_price ?? 0)),
            render: (r: BranchPrice) => money(r.quantity * ((r.effective.selling_price ?? 0) - (r.effective.cost_price ?? 0))),
          }] : []),
          ...(canEdit ? [{
            key: "_edit", header: "", align: "right" as const,
            render: (r: BranchPrice) => <Button size="sm" variant="ghost" aria-label={t("Change prices for {branch}", { branch: r.branch_name })} onClick={() => open(r)}><Pencil className="size-4" /></Button>,
          }] : []),
        ]}
      />
      <p className="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">{t("Prices in bold are the branch's own; grey prices come from the product.")}</p>

      <Modal
        open={Boolean(editing)}
        onClose={() => setEditing(null)}
        title={t("Prices at {branch}", { branch: editing?.branch_name ?? "" })}
        description={t("Leave a box empty to use the product's own price at this branch.")}
        footer={<><Button variant="secondary" onClick={() => setEditing(null)}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save prices")}</Button></>}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          {showCost && (
            <Field label={t("Buying price")} error={errors.cost_price}>
              {(p) => <Input {...p} inputMode="decimal" className="num" value={form.cost_price} onChange={(e) => setForm({ ...form, cost_price: numeric(e.target.value) })} />}
            </Field>
          )}
          <Field label={t("Selling price")} error={errors.selling_price}>
            {(p) => <Input {...p} inputMode="decimal" className="num" value={form.selling_price} onChange={(e) => setForm({ ...form, selling_price: numeric(e.target.value) })} />}
          </Field>
          <Field label={t("Wholesale price")} error={errors.wholesale_price}>
            {(p) => <Input {...p} inputMode="decimal" className="num" value={form.wholesale_price} onChange={(e) => setForm({ ...form, wholesale_price: numeric(e.target.value) })} />}
          </Field>
          {showCost && (
            <Field label={t("Lowest price allowed")} error={errors.min_selling_price}>
              {(p) => <Input {...p} inputMode="decimal" className="num" value={form.min_selling_price} onChange={(e) => setForm({ ...form, min_selling_price: numeric(e.target.value) })} />}
            </Field>
          )}
        </div>
      </Modal>
    </Panel>
  );
}
