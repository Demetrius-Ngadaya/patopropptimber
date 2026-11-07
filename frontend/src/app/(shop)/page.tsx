"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import type { ApiResponse, Paginated } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useCart, type ShopProduct } from "@/features/shop/cart";
import { usePublicSettings } from "@/lib/public-settings";
import { cn } from "@/lib/format";
import { ProductCard } from "@/features/shop/components/ProductCard";
import { Button, EmptyState, ErrorState, Select, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Category = { id: number; name: string; slug: string; products_count: number };
type ShopBranch = { id: number; name: string; location: string | null };

function Catalog() {
  const t = useT();
  const params = useSearchParams();
  const router = useRouter();
  const { settings } = usePublicSettings();
  const { branchId, setBranchId } = useCart();
  const search = params.get("search") ?? "";
  const category = params.get("category") ?? "";
  const [sort, setSort] = useState("");
  const [perPage, setPerPage] = useState(24);
  const categories = useApi<ApiResponse<Category[]>>("shop/categories");
  const branches = useApi<ApiResponse<ShopBranch[]>>("shop/branches");
  const products = useApi<Paginated<ShopProduct>>("shop/products", { search, category, sort, per_page: perPage, branch_id: branchId ?? undefined });

  useEffect(() => {
    setPerPage(24);
  }, [search, category, sort]);
  useEffect(() => {
    const list = branches.data?.data;
    if (list?.length && (branchId === null || !list.some((b) => b.id === branchId))) setBranchId(list[0].id);
  }, [branches.data, branchId, setBranchId]);

  const go = (slug: string) => {
    const q = new URLSearchParams();
    if (slug) q.set("category", slug);
    if (search) q.set("search", search);
    router.push(q.toString() ? `/?${q}` : "/");
  };
  const meta = products.data?.meta;
  const cats = categories.data?.data.filter((c) => c.products_count > 0) ?? [];

  return (
    <>
      {!search && !category && (
        <section className="mb-8 overflow-hidden rounded-xl bg-night-900 px-6 py-8 text-white sm:px-10 sm:py-12">
          <h1 className="max-w-xl text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{settings?.shop_tagline || t("Order from {value} and collect or get it delivered.", { value: settings?.business_name ?? "our shop" })}</h1>
          <p className="mt-3 max-w-lg text-ink-300">
            {settings?.online_payments_shop
              ? t("Pay now with mobile money, or when you collect or it arrives.")
              : <>{t("Pay when you collect or when it arrives")}{settings?.shop_delivery_enabled ? "" : t(" at the counter")}.</>}
            {settings && Number(settings.shop_min_order_amount) > 0 && t(" Minimum order {currency} {shop_min_order_amount}.", { currency: settings.currency, shop_min_order_amount: Number(settings.shop_min_order_amount).toLocaleString() })}
          </p>
        </section>
      )}

      <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-ink-950">{search ? t("Results for “{search}”", { search: search }) : cats.find((c) => c.slug === category)?.name ?? t("All products")}</h2>
          {meta && <p className="text-sm text-ink-500">{t("{total} product", { total: meta.total })}{meta.total === 1 ? "" : "s"}</p>}
        </div>
        <div className="flex flex-wrap gap-2">
          {(branches.data?.data.length ?? 0) > 1 && (
            <Select aria-label={t("Branch")} className="w-48" value={branchId ?? ""} onChange={(e) => setBranchId(Number(e.target.value))}>
              {branches.data!.data.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </Select>
          )}
          <Select aria-label={t("Sort")} className="w-44" value={sort} onChange={(e) => setSort(e.target.value)}>
            <option value="">{t("Name A–Z")}</option>
            <option value="price_asc">{t("Lowest price")}</option>
            <option value="price_desc">{t("Highest price")}</option>
            <option value="newest">{t("Newest")}</option>
          </Select>
        </div>
      </div>

      {cats.length > 0 && (
        <nav aria-label={t("Categories")} className="-mx-4 mb-6 flex gap-2 overflow-x-auto px-4 pb-1">
          {[{ slug: "", name: "Everything" }, ...cats].map((c) => (
            <button key={c.slug} type="button" onClick={() => go(c.slug)}
              className={cn("shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1 transition-colors", category === c.slug ? "bg-night-900 text-white ring-ink-900" : "bg-surface text-ink-700 ring-ink-200 hover:ring-ink-400")}>
              {c.name}
            </button>
          ))}
        </nav>
      )}

      {products.isLoading ? <Spinner variant="cards" /> : products.error ? <ErrorState message={products.error.message} onRetry={() => products.refetch()} /> : !products.data?.data.length ? (
        <EmptyState title={t("Nothing found")} message={search ? t("Try a shorter or different word.") : t("No products are listed here yet.")} />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
            {products.data.data.map((p) => <ProductCard key={p.id} product={p} />)}
          </div>
          {meta && meta.total > meta.per_page && meta.per_page < 60 && (
            <div className="mt-8 text-center">
              <Button variant="secondary" loading={products.isFetching} onClick={() => setPerPage((n) => Math.min(n + 24, 60))}>{t("Show more")}</Button>
            </div>
          )}
          {meta && meta.last_page > 1 && meta.per_page >= 60 && <p className="mt-6 text-center text-sm text-ink-500">{t("Showing the first 60. Search or pick a category to find more.")}</p>}
        </>
      )}
    </>
  );
}

export default function ShopHome() {
  return <Suspense fallback={<Spinner variant="cards" />}><Catalog /></Suspense>;
}
