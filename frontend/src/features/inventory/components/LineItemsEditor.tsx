"use client";

import { Trash2 } from "lucide-react";
import { money, qty, toNumber } from "@/lib/format";
import type { Product } from "@/lib/types";
import { Button, Input } from "@/components/ui";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { useT } from "@/lib/i18n";

export type EditorLine = { product: Product; quantity: string; unit_price: string };

/** Product + quantity (+ optional price) lines, used by transfers and allocations. */
export function LineItemsEditor({ lines, onChange, showPrice, priceLabel = "Price / unit" }: { lines: EditorLine[]; onChange: (l: EditorLine[]) => void; showPrice?: boolean; priceLabel?: string }) {
  const t = useT();
  const add = (p: Product) => {
    if (lines.some((l) => l.product.id === p.id)) return;
    onChange([...lines, { product: p, quantity: "1", unit_price: p.selling_price }]);
  };
  const upd = (i: number, patch: Partial<EditorLine>) => onChange(lines.map((l, x) => (x === i ? { ...l, ...patch } : l)));

  return (
    <div>
      <ProductPicker source="admin/products" onSelect={add} placeholder={t("Scan or search a product to add")} />
      <table className="mt-4 w-full text-sm">
        <thead>
          <tr className="border-b border-ink-200 text-left text-[12.5px] text-ink-500">
            <th className="py-2">{t("Product")}</th>
            <th className="py-2 text-right">{t("In stock")}</th>
            <th className="py-2 text-right">{t("Quantity")}</th>
            {showPrice && <th className="py-2 text-right">{priceLabel}</th>}
            {showPrice && <th className="py-2 text-right">{t("Amount")}</th>}
            <th className="py-2"><span className="sr-only">{t("Remove")}</span></th>
          </tr>
        </thead>
        <tbody>
          {lines.map((l, i) => {
            const stock = toNumber(l.product.stock_quantity);
            const over = toNumber(l.quantity) > stock;
            return (
              <tr key={l.product.id} className="border-b border-ink-100">
                <td className="py-2 font-medium">{l.product.name}</td>
                <td className={`num py-2 text-right ${over ? "text-ember-600" : "text-ink-500"}`}>{qty(stock, l.product.unit?.short_name)}</td>
                <td className="py-2 text-right"><Input aria-label={t("Quantity of {name}", { name: l.product.name })} type="number" min={0} step="any" className="num ml-auto h-9 w-24 text-right" value={l.quantity} onChange={(e) => upd(i, { quantity: e.target.value })} /></td>
                {showPrice && <td className="py-2 text-right"><Input aria-label={t("Price of {name}", { name: l.product.name })} type="number" min={0} step="any" className="num ml-auto h-9 w-28 text-right" value={l.unit_price} onChange={(e) => upd(i, { unit_price: e.target.value })} /></td>}
                {showPrice && <td className="num py-2 text-right">{money(toNumber(l.quantity) * toNumber(l.unit_price))}</td>}
                <td className="py-2 text-right"><Button size="sm" variant="ghost" aria-label={t("Remove {name}", { name: l.product.name })} onClick={() => onChange(lines.filter((_, x) => x !== i))}><Trash2 className="size-4 text-ember-600" /></Button></td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {!lines.length && <p className="py-6 text-center text-sm text-ink-500">{t("No products added yet.")}</p>}
    </div>
  );
}
