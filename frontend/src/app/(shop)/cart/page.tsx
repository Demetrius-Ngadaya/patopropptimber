"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ShoppingBasket, Trash2 } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { useCart } from "@/features/shop/cart";
import { money } from "@/lib/format";
import { usePublicSettings } from "@/lib/public-settings";
import { ProductImage, QuantityStepper } from "@/features/shop/components/ProductCard";
import { Button, EmptyState } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function CartPage() {
  const t = useT();
  const { items, subtotal, setQuantity, remove } = useCart();
  const { settings } = usePublicSettings();
  const { user } = useAuth();
  const router = useRouter();
  const cur = settings?.currency ?? "";
  const min = Number(settings?.shop_min_order_amount ?? 0);
  const short = min > 0 && subtotal < min;

  if (!items.length) {
    return <EmptyState icon={<ShoppingBasket className="size-8" />} title={t("Your basket is empty")} message={t("Add products from the shop and they will appear here.")} action={<Link href="/" className="inline-flex h-10 items-center rounded-md bg-brand-600 px-4 text-sm font-medium text-white hover:bg-brand-700">{t("Browse products")}</Link>} />;
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
      <section>
        <h1 className="mb-4 text-2xl font-semibold text-ink-950">{t("Your basket")}</h1>
        <ul className="divide-y divide-ink-100 rounded-lg bg-surface ring-1 ring-ink-200/70">
          {items.map((i) => (
            <li key={i.product_id} className="flex gap-3 p-3 sm:gap-4 sm:p-4">
              <Link href={`/products/${i.slug}`} className="w-20 shrink-0 overflow-hidden rounded-md sm:w-24"><ProductImage product={i} /></Link>
              <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex justify-between gap-3">
                  <Link href={`/products/${i.slug}`} className="font-medium text-ink-900 hover:underline">{i.name}</Link>
                  <p className="num shrink-0 font-semibold">{money(i.price * i.quantity)}</p>
                </div>
                <p className="text-sm text-ink-500"><span className="num">{cur} {money(i.price)}</span>{i.unit ? t(" per {unit}", { unit: i.unit }) : t(" each")}</p>
                <div className="mt-auto flex items-center justify-between gap-2">
                  <QuantityStepper value={i.quantity} onChange={(v) => setQuantity(i.product_id, v)} allowDecimal={i.allow_decimal} />
                  <button type="button" onClick={() => remove(i.product_id)} className="flex items-center gap-1 rounded-md px-2 py-1.5 text-sm text-ink-500 hover:bg-ember-50 hover:text-ember-700"><Trash2 className="size-4" /> {t("Remove")}</button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      </section>
      <aside className="lg:pt-12">
        <div className="rounded-lg bg-surface p-5 ring-1 ring-ink-200/70 lg:sticky lg:top-24">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-ink-600">{t("Items")}</dt><dd className="num">{t("{count} product", { count: items.length })}{items.length === 1 ? "" : "s"}</dd></div>
            <div className="flex justify-between text-base font-semibold"><dt>{t("Subtotal")}</dt><dd className="num">{cur} {money(subtotal)}</dd></div>
          </dl>
          <p className="mt-2 text-xs text-ink-500">{t("Delivery, if you choose it, is added at checkout. Final prices are confirmed when you place the order.")}</p>
          {short && <p className="mt-3 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-700">{t("The minimum order is {cur} {min}. Add {cur} {value} more.", { cur: cur, min: money(min), value: money(min - subtotal) })}</p>}
          <Button size="lg" className="mt-4 w-full" disabled={short} onClick={() => router.push(user?.type === "customer" ? "/checkout" : "/login?next=/checkout")}>
            {user?.type === "customer" ? t("Go to checkout") : t("Log in to check out")}
          </Button>
          {!user && settings?.shop_allow_registration && <p className="mt-3 text-center text-sm text-ink-600">{t("New here?")} <Link href="/register?next=/checkout" className="font-medium text-brand-700 hover:underline">{t("Create an account")}</Link></p>}
        </div>
      </aside>
    </div>
  );
}
