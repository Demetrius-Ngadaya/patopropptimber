"use client";

import { useState } from "react";
import { Check, ImageOff } from "lucide-react";
import { cn, money, qty, toNumber } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import { useT } from "@/lib/i18n";
import type { Paginated } from "@/lib/api";
import type { Product } from "@/lib/types";
import { Button, SearchInput, Skeleton } from "@/components/ui";

/**
 * Products shown as cards to tap, like the till (POS). Optional: used next to the
 * search box when receiving goods, for people who prefer picking by sight.
 * Cards already in the list are ticked; tapping one again does nothing.
 */
export function ProductCardsPicker({ onSelect, selectedIds, priceLabel = "cost" }: {
  onSelect: (p: Product) => void;
  selectedIds: number[];
  /** Which price to show on each card. */
  priceLabel?: "cost" | "selling";
}) {
  const t = useT();
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState<number | null>(null);
  const [perPage, setPerPage] = useState(24);
  const term = useDebounced(search);
  const { categories = [] } = useLookups(["categories"]);
  const list = useApi<Paginated<Product>>("admin/products", {
    search: term || undefined,
    category_id: category,
    active: true,
    sort: "name",
    direction: "asc",
    per_page: perPage,
  });
  const products = list.data?.data ?? [];
  const total = list.data?.meta?.total ?? products.length;
  const chosen = new Set(selectedIds);

  const chip = (active: boolean) =>
    cn("whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-medium", active ? "bg-night-900 text-white" : "bg-surface text-ink-700 ring-1 ring-ink-200 hover:bg-ink-50");

  return (
    <div className="rounded-[var(--radius-panel)] bg-ink-50/60 p-3 ring-1 ring-ink-200/70">
      <SearchInput value={search} onChange={(v) => { setSearch(v); setPerPage(24); }} placeholder={t("Filter the cards by name or code")} />
      {categories.length > 0 && (
        <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
          <button type="button" className={chip(category === null)} onClick={() => { setCategory(null); setPerPage(24); }}>{t("All")}</button>
          {categories.map((c) => (
            <button key={c.id} type="button" className={chip(category === c.id)} onClick={() => { setCategory(c.id); setPerPage(24); }}>{c.name}</button>
          ))}
        </div>
      )}

      {list.isLoading && !list.data ? (
        <div role="status" aria-busy="true" className="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-36 rounded-[var(--radius-panel)]" />)}
        </div>
      ) : products.length === 0 ? (
        <p className="py-8 text-center text-sm text-ink-500">{t("No products found.")}</p>
      ) : (
        <div className="mt-3 grid max-h-[32rem] grid-cols-2 gap-2.5 overflow-y-auto pr-1 sm:grid-cols-3 lg:grid-cols-4">
          {products.map((p) => {
            const added = chosen.has(p.id);
            const stock = toNumber(p.stock_quantity);
            const price = priceLabel === "cost" && p.cost_price != null ? p.cost_price : p.selling_price;
            return (
              <button
                key={p.id}
                type="button"
                onClick={() => !added && onSelect(p)}
                aria-pressed={added}
                aria-label={added ? t("{name} (already added)", { name: p.name }) : t("Add {name}", { name: p.name })}
                className={cn(
                  "relative flex flex-col overflow-hidden rounded-[var(--radius-panel)] bg-surface text-left ring-1 transition focus-visible:ring-2",
                  added ? "ring-2 ring-brand-500" : "ring-ink-200/80 hover:ring-brand-500",
                )}
              >
                <span className="relative block aspect-[4/3] w-full bg-ink-100">
                  {p.image_url ? (
                    // eslint-disable-next-line @next/next/no-img-element -- product photos come from the API's storage
                    <img src={p.image_url} alt="" loading="lazy" className="size-full object-cover" />
                  ) : (
                    <span className="grid size-full place-items-center text-ink-300"><ImageOff className="size-6" /></span>
                  )}
                  {added && (
                    <span className="absolute right-1.5 top-1.5 grid size-6 place-items-center rounded-full bg-brand-600 text-white shadow"><Check className="size-4" /></span>
                  )}
                </span>
                <span className="flex flex-1 flex-col justify-between p-2.5">
                  <span className="line-clamp-2 text-sm font-medium leading-snug text-ink-900">{p.name}</span>
                  <span className="mt-1.5 flex items-end justify-between gap-1">
                    <span className="num text-[14px] font-semibold text-brand-700" title={priceLabel === "cost" ? t("Buying price") : t("Selling price")}>{money(price)}</span>
                    {!p.is_service && <span className={cn("num text-xs", stock <= 0 ? "text-ember-600" : "text-ink-500")}>{stock <= 0 ? t("none") : qty(stock, p.unit?.short_name)}</span>}
                  </span>
                </span>
              </button>
            );
          })}
        </div>
      )}

      {products.length > 0 && products.length < total && (
        <div className="mt-3 text-center">
          <Button size="sm" variant="secondary" loading={list.isFetching} onClick={() => setPerPage((n) => n + 24)}>
            {t("Show more ({shown} of {total})", { shown: products.length, total })}
          </Button>
        </div>
      )}
    </div>
  );
}
