"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, ShoppingBasket } from "lucide-react";
import { toast } from "sonner";
import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useCart, type ShopProduct } from "@/features/shop/cart";
import { qty } from "@/lib/format";
import { usePublicSettings } from "@/lib/public-settings";
import { PriceTag, ProductCard, ProductImage, QuantityStepper } from "@/features/shop/components/ProductCard";
import { Button, ErrorState, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function ProductPage({ params }: { params: Promise<{ slug: string }> }) {
  const t = useT();
  const { slug } = use(params);
  const { add, branchId, items } = useCart();
  const { settings } = usePublicSettings();
  const q = useApi<ApiResponse<{ product: ShopProduct; related: ShopProduct[] }>>(`shop/products/${encodeURIComponent(slug)}`, { branch_id: branchId ?? undefined });
  const [quantity, setQuantity] = useState(1);

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error) return <ErrorState message={q.error.status === 404 ? t("This product is no longer available.") : q.error.message} />;
  const { product: p, related } = q.data!.data;
  const inBasket = items.find((i) => i.product_id === p.id);

  return (
    <>
      <Link href="/" className="mb-4 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("All products")}</Link>
      <div className="grid gap-8 md:grid-cols-2">
        <div className="overflow-hidden rounded-xl ring-1 ring-ink-200/70"><ProductImage product={p} /></div>
        <div>
          {p.category && <Link href={`/?category=${p.category.slug}`} className="text-sm font-medium text-brand-700 hover:underline">{p.category.name}</Link>}
          <h1 className="mt-1 text-3xl font-semibold tracking-tight text-ink-950">{p.name}</h1>
          {p.brand && <p className="mt-1 text-ink-500">{p.brand}</p>}
          <div className="mt-5"><PriceTag size="lg" price={p.selling_price} unit={p.unit} currency={settings?.currency} /></div>
          <p className="mt-4 text-sm">
            {p.in_stock ? <span className="font-medium text-brand-700">{t("In stock")}{p.available_quantity != null ? t(" · {available_quantity} available", { available_quantity: qty(p.available_quantity, p.unit) }) : ""}</span> : <span className="font-medium text-ember-600">{t("Out of stock")}</span>}
          </p>
          {p.in_stock && (
            <div className="mt-6 flex flex-wrap items-center gap-3">
              <QuantityStepper value={quantity} onChange={setQuantity} allowDecimal={p.allow_decimal} max={p.available_quantity} />
              <Button size="lg" icon={<ShoppingBasket className="size-4" />} disabled={quantity <= 0} onClick={() => { add(p, quantity); toast.success(t("{name} added to your basket.", { name: p.name })); }}>{t("Add to basket")}</Button>
            </div>
          )}
          {p.allow_decimal && <p className="mt-2 text-xs text-ink-500">{t("Sold by weight or measure: you can order part quantities like 1.5.")}</p>}
          {inBasket && <p className="mt-3 text-sm text-ink-600"><span className="num">{qty(inBasket.quantity, inBasket.unit)}</span> {t("already in your")} <Link href="/cart" className="font-medium text-brand-700 hover:underline">{t("basket")}</Link>.</p>}
          {p.description && <div className="mt-8 whitespace-pre-line border-t border-ink-200 pt-6 leading-relaxed text-ink-700">{p.description}</div>}
        </div>
      </div>
      {related.length > 0 && (
        <section className="mt-14">
          <h2 className="mb-4 text-lg font-semibold text-ink-950">{t("More in")} {p.category?.name ?? t("this category")}</h2>
          <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">{related.map((r) => <ProductCard key={r.id} product={r} />)}</div>
        </section>
      )}
    </>
  );
}
