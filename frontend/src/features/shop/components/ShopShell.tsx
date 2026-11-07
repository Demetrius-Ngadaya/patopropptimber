"use client";

import Link from "next/link";
import { BrandLogo } from "@/components/brand/BrandLogo";
import { LanguageSwitch, ThemeSwitch } from "@/components/layout/PreferenceSwitches";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { LogOut, Package, Search, ShoppingBasket, Store, User } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { useCart } from "@/features/shop/cart";
import { usePublicSettings } from "@/lib/public-settings";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { BRAND } from "@/lib/brand";
import { AssistantWidget, ContactWidget } from "./FloatingWidgets";
import { AppDownloadLink } from "@/features/mobile-app/AppDownload";

export function ShopShell({ children }: { children: React.ReactNode }) {
  const t = useT();
  const { settings } = usePublicSettings();
  const { user, logout } = useAuth();
  const { count } = useCart();
  const router = useRouter();
  const pathname = usePathname();
  const [term, setTerm] = useState("");
  const [menu, setMenu] = useState(false);

  useEffect(() => {
    setTerm(new URLSearchParams(window.location.search).get("search") ?? "");
    setMenu(false);
  }, [pathname]);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const q = term.trim();
    router.push(q ? `/?search=${encodeURIComponent(q)}` : "/");
  };
  const name = settings?.business_name ?? BRAND.product;

  return (
    <div className="flex min-h-dvh flex-col bg-ink-50">
      <header className="sticky top-0 z-30 border-b border-ink-200/70 bg-surface/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
          <Link href="/" className="flex shrink-0 items-center gap-2.5">
            <BrandLogo size="sm" ring={false} className="ring-1 ring-ink-200" />
            <span className="hidden max-w-[16ch] truncate font-semibold text-ink-950 sm:block">{name}</span>
          </Link>
          <form onSubmit={submit} role="search" className="relative mx-auto w-full max-w-md">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
            <input value={term} onChange={(e) => setTerm(e.target.value)} type="search" placeholder={t("Search products")} aria-label={t("Search products")}
              className="h-10 w-full rounded-full bg-ink-50 pl-9 pr-4 text-sm ring-1 ring-ink-200 placeholder:text-ink-400 focus:bg-surface focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </form>
          <Link href="/cart" className="relative grid size-10 shrink-0 place-items-center rounded-full text-ink-700 hover:bg-ink-100" aria-label={t("Basket, {count} items", { count: count })}>
            <ShoppingBasket className="size-5" />
            {count > 0 && <span className="num absolute -right-0.5 -top-0.5 grid min-w-5 place-items-center rounded-full bg-saffron-500 px-1 text-[11px] font-semibold text-ink-950">{count}</span>}
          </Link>
          <div className="hidden shrink-0 items-center sm:flex"><LanguageSwitch compact /><ThemeSwitch /></div>
          {user?.type === "customer" ? (
            <div className="relative shrink-0">
              <button type="button" onClick={() => setMenu((m) => !m)} className="grid size-10 place-items-center rounded-full bg-night-900 text-sm font-semibold text-white" aria-label={t("Account menu")} aria-expanded={menu}>
                {user.name.slice(0, 1).toUpperCase()}
              </button>
              {menu && (
                <div className="absolute right-0 mt-2 w-52 overflow-hidden rounded-lg bg-surface py-1 shadow-lg ring-1 ring-ink-200">
                  <p className="truncate px-3 py-2 text-sm font-medium">{user.name}</p>
                  <Link href="/account/orders" className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-ink-50"><Package className="size-4" /> {t("My orders")}</Link>
                  <Link href="/account" className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-ink-50"><User className="size-4" /> {t("My details")}</Link>
                  <button type="button" onClick={() => logout(t("You have logged out."))} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ember-700 hover:bg-ember-50"><LogOut className="size-4" /> {t("Log out")}</button>
                </div>
              )}
            </div>
          ) : user?.type === "staff" ? (
            <Link href="/admin" className="shrink-0 rounded-full px-3 py-2 text-sm font-medium text-ink-700 hover:bg-ink-100">{t("Back office")}</Link>
          ) : (
            <Link href={`/login?next=${encodeURIComponent(pathname)}`} className="shrink-0 rounded-full px-3 py-2 text-sm font-medium text-ink-800 hover:bg-ink-100">{t("Log in")}</Link>
          )}
        </div>
      </header>

      {settings && !settings.shop_enabled && !pathname.startsWith("/account") ? (
        <main className="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-4 py-20 text-center">
          <Store className="size-10 text-ink-400" />
          <h1 className="mt-4 text-2xl font-semibold text-ink-950">{t("The online shop is closed right now")}</h1>
          <p className="mt-2 text-ink-600">{t("You can still visit us")}{settings.business_address ? t(" at {business_address}", { business_address: settings.business_address }) : ""}{settings.business_phone ? t(" or call {business_phone}", { business_phone: settings.business_phone }) : ""}.</p>
        </main>
      ) : (
        <main className={cn("mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:py-8")}>{children}</main>
      )}

      <footer className="border-t border-ink-200/70 bg-surface">
        <div className="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-6 text-sm text-ink-500 sm:flex-row sm:items-center sm:justify-between">
          <p><span className="font-medium text-ink-800">{name}</span>{settings?.business_address ? ` · ${settings.business_address}` : ""}</p>
          <p>{settings?.business_phone}{settings?.business_phone && settings?.business_email ? " · " : ""}{settings?.business_email}</p>
        </div>
        <p className="pb-24 text-center text-xs text-ink-400 sm:pb-6"><Link href="/faq" className="hover:underline">{t("Questions & answers")}</Link><AppDownloadLink before=" · " /> · {BRAND.poweredBy}</p>
      </footer>
      <ContactWidget />
      <AssistantWidget />
    </div>
  );
}
