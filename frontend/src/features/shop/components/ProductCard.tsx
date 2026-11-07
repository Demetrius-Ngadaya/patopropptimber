"use client";

import Link from "next/link";
import { Plus } from "lucide-react";
import { toast } from "sonner";
import { useCart, type ShopProduct } from "@/features/shop/cart";
import { money } from "@/lib/format";
import { usePublicSettings } from "@/lib/public-settings";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { useState } from "react";
import { NumericInput } from "@/components/ui";

/** Square photo, or the product's initial on a tinted block when there is no photo. */
export function ProductImage({ product, className }: { product: Pick<ShopProduct, "name" | "image_url">; className?: string }) {
  return product.image_url ? (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={product.image_url} alt="" loading="lazy" className={cn("aspect-square w-full bg-surface object-cover", className)} />
  ) : (
    <div className={cn("grid aspect-square w-full place-items-center bg-ink-100 text-4xl font-semibold text-ink-300", className)} aria-hidden>
      {product.name.slice(0, 1).toUpperCase()}
    </div>
  );
}

/** The shop's signature element: a hanging price tag with a punched hole. */
export function PriceTag({ price, unit, currency, size = "md" }: { price: number | string; unit?: string | null; currency?: string; size?: "md" | "lg" }) {
  return (
    <span className={cn("price-tag inline-flex items-baseline gap-1 bg-saffron-300 font-semibold text-ink-950", size === "lg" ? "py-2 pl-7 pr-4 text-2xl" : "py-1 pl-5 pr-2.5 text-[15px]")}>
      <span className={cn("font-medium text-ink-700", size === "lg" ? "text-sm" : "text-[11px]")}>{currency}</span>
      <span className="num">{money(price)}</span>
      {unit && <span className={cn("font-normal text-ink-700", size === "lg" ? "text-sm" : "text-xs")}>/ {unit}</span>}
    </span>
  );
}

export function ProductCard({ product }: { product: ShopProduct }) {
  const t = useT();
  const { add } = useCart();
  const [quantity, setQuantity] = useState(1);
  const { settings } = usePublicSettings();
  return (
    <article className="group relative flex flex-col overflow-hidden rounded-lg bg-surface ring-1 ring-ink-200/70 transition-shadow hover:shadow-md">
      <Link href={`/products/${product.slug}`} className="block">
        <ProductImage product={product} className={cn(!product.in_stock && "opacity-50")} />
      </Link>
      <div className="flex flex-1 flex-col gap-2 p-3">
        <div className="-ml-3"><PriceTag price={product.selling_price} unit={product.unit} currency={settings?.currency} /></div>
        <Link href={`/products/${product.slug}`} className="line-clamp-2 text-sm font-medium leading-snug text-ink-900 hover:underline">{product.name}</Link>
        {product.category && <p className="text-xs text-ink-500">{product.category.name}</p>}
        <div className="mt-auto pt-1">
          {product.in_stock ? (
            <div className="space-y-2">
              <QuantityStepper size="sm" className="w-full [&>div]:w-full" value={quantity} onChange={setQuantity} allowDecimal={product.allow_decimal} max={product.available_quantity} />
              <button type="button" onClick={() => { add(product, quantity); toast.success(t("{name} added to your basket.", { name: product.name })); setQuantity(1); }}
                className="flex h-9 w-full items-center justify-center gap-1.5 rounded-md bg-brand-600 text-sm font-medium text-white hover:bg-brand-700">
                <Plus className="size-4" /> {t("Add")}
              </button>
            </div>
          ) : (
            <p className="flex h-9 items-center justify-center rounded-md bg-ink-100 text-sm text-ink-500">{t("Out of stock")}</p>
          )}
        </div>
      </div>
    </article>
  );
}

/**
 * Quantity with − / + and a box to type any amount (e.g. 48). Clearing the box
 * to type a new number never removes the item; the amount is kept within stock.
 */
export function QuantityStepper({ value, onChange, allowDecimal, max, size = "md", className }: {
  value: number; onChange: (v: number) => void; allowDecimal: boolean; max?: number | null; size?: "sm" | "md"; className?: string;
}) {
  const t = useT();
  const [text, setText] = useState<string | null>(null);
  const step = allowDecimal ? 0.5 : 1;
  const min = allowDecimal ? 0.001 : 1;
  const clamp = (v: number) => Math.max(min, max != null && max > 0 ? Math.min(v, max) : v);
  const typed = text !== null ? Number(text) : NaN;
  const overMax = max != null && max > 0 && typed > max;
  const h = size === "sm" ? "h-9" : "h-10";
  return (
    <div className={cn("inline-flex flex-col", className)}>
      <div className={cn("inline-flex items-center overflow-hidden rounded-md bg-surface ring-1 ring-ink-200", h)}>
        <button type="button" className="h-full w-9 shrink-0 text-lg text-ink-700 hover:bg-ink-50 disabled:opacity-40" aria-label={t("Less")}
          disabled={value <= min} onClick={() => { setText(null); onChange(clamp(Math.round((value - step) * 1000) / 1000)); }}>−</button>
        <NumericInput aria-label={t("Quantity")} step={allowDecimal ? "any" : "1"} min={0}
          className="num h-full w-full min-w-14 flex-1 border-x border-ink-200 bg-transparent text-center text-sm font-medium focus:bg-brand-50/40 focus:outline-none"
          value={text ?? String(value)}
          onFocus={(e) => e.target.select()}
          onChange={(e) => {
            const v = e.target.value;
            setText(v);
            const n = Number(v);
            if (v !== "" && Number.isFinite(n) && n > 0) onChange(clamp(allowDecimal ? n : Math.floor(n)));
          }}
          onBlur={() => setText(null)}
          onKeyDown={(e) => { if (e.key === "Enter") (e.target as HTMLInputElement).blur(); }}
        />
        <button type="button" className="h-full w-9 shrink-0 text-lg text-ink-700 hover:bg-ink-50 disabled:opacity-40" aria-label={t("More")}
          disabled={max != null && max > 0 && value >= max} onClick={() => { setText(null); onChange(clamp(Math.round((value + step) * 1000) / 1000)); }}>+</button>
      </div>
      {overMax && <span className="mt-1 text-xs text-ember-600">{t("Only {count} available", { count: String(max) })}</span>}
    </div>
  );
}
