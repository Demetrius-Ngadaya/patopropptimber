"use client";

import Link from "next/link";
import { use } from "react";
import { BarChart3, Boxes, ExternalLink, LockKeyhole, Phone, Printer, ScanBarcode, ShoppingCart, SlidersHorizontal, Tag } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, date, dateTime, money, qty } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import { BrandLogo } from "@/components/brand/BrandLogo";
import { ErrorState, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

type PublicView = {
  fields: string[];
  product: Partial<Record<"name" | "price" | "wholesale_price" | "description" | "category" | "brand" | "unit" | "sku" | "barcode" | "image" | "availability", string | null>>;
  business: { name: string; phone: string; address: string; logo_url: string | null } | null;
  shop_url: string | null;
};
type Period = { quantity: number; amount: number };
type StaffView = {
  product: { id: number; name: string; sku: string | null; barcode: string | null; category: string | null; unit: string; image_url: string | null; selling_price: number; wholesale_price: number | null; wholesale_min_qty: number | null; is_active: boolean };
  sections: {
    cost?: { cost_price: number; margin_percent: number | null };
    stock?: { unit: string; reorder_level: number; total: number; branches: { branch: string; quantity: number }[]; next_expiry: { date: string; quantity: number } | null };
    sales?: { today: Period; days_7: Period; days_30: Period; last_sold_at: string | null };
  };
  actions: { key: "open" | "sell" | "adjust" | "labels"; url: string }[];
};

const ACTIONS = {
  open: { label: "Open product", icon: ExternalLink },
  sell: { label: "Sell", icon: ShoppingCart },
  adjust: { label: "Adjust stock", icon: SlidersHorizontal },
  labels: { label: "Print label", icon: Printer },
} as const;

/**
 * What a scanned product QR code opens. Customers see what the admin chose to
 * show; signed-in staff see stock, sales and cost according to their role.
 */
export default function ScanPage({ params }: { params: Promise<{ token: string }> }) {
  const t = useT();
  const { token } = use(params);
  const { user, loading } = useAuth();
  const staff = user?.type === "staff";
  const pub = useApi<ApiResponse<PublicView>>(staff || loading ? null : `public/products/scan/${token}`);
  const mine = useApi<ApiResponse<StaffView>>(staff ? `admin/products/scan/${token}` : null);

  const { settings } = usePublicSettings();
  const busy = loading || pub.isLoading || mine.isLoading;
  const error = pub.error ?? mine.error;

  return (
    <div className="min-h-dvh bg-paper">
      <header className="keep-light flex items-center gap-2.5 bg-night-900 px-4 py-3 text-white">
        <BrandLogo size="sm" />
        <span className="truncate text-[15px] font-semibold">{settings?.business_name ?? t("Product")}</span>
        <span className="flex-1" />
        <ScanBarcode className="size-5 text-saffron-300" aria-hidden />
      </header>
      <main className="mx-auto w-full max-w-md px-4 py-5">
        {busy ? <Spinner variant="compact" /> : error ? (
          <ErrorState message={error.status === 404 ? t("This code does not belong to a product, or QR codes are switched off.") : error.message} />
        ) : staff && mine.data ? <Staff view={mine.data.data} /> : pub.data ? (
          <Public view={pub.data.data} next={`/q/${token}`} />
        ) : null}
      </main>
    </div>
  );
}

function Public({ view, next }: { view: PublicView; next: string }) {
  const t = useT();
  const p = view.product;
  const rows: [string, string | null | undefined][] = [
    ["Wholesale price", p.wholesale_price], ["Category", p.category], ["Brand", p.brand], ["Unit", p.unit], ["SKU", p.sku], ["Barcode", p.barcode],
  ];
  return (
    <article className="space-y-4">
      <div className="overflow-hidden rounded-xl bg-surface shadow-sm ring-1 ring-ink-100">
        {/* eslint-disable-next-line @next/next/no-img-element -- served by the API storage */}
        {p.image && <img src={p.image} alt="" className="aspect-[4/3] w-full object-cover" />}
        <div className="space-y-3 p-4">
          {p.name && <h1 className="text-xl font-semibold leading-tight text-ink-900">{p.name}</h1>}
          {p.price && <p className="num text-2xl font-bold text-brand-700">{p.price}</p>}
          {p.availability && <p className={cn("inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium", p.availability === t("In stock") || p.availability === "In stock" ? "bg-brand-100 text-brand-700" : "bg-ember-100 text-ember-700")}>{p.availability}</p>}
          {p.description && <p className="whitespace-pre-line text-sm leading-relaxed text-ink-700">{p.description}</p>}
          {rows.some(([, v]) => v) && (
            <dl className="divide-y divide-ink-100 text-sm">
              {rows.filter(([, v]) => v).map(([k, v]) => <div key={k} className="flex justify-between gap-4 py-2"><dt className="text-ink-500">{t(k)}</dt><dd className="text-right font-medium text-ink-900">{v}</dd></div>)}
            </dl>
          )}
          {view.shop_url && <Link href={view.shop_url} className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline"><ShoppingCart className="size-4" />{t("Order it online")}</Link>}
        </div>
      </div>
      {view.business && (
        <div className="rounded-xl bg-surface p-4 text-sm ring-1 ring-ink-100">
          <p className="font-semibold text-ink-900">{view.business.name}</p>
          {view.business.address && <p className="text-ink-600">{view.business.address}</p>}
          {view.business.phone && <a href={`tel:${view.business.phone.replace(/\s/g, "")}`} className="mt-2 inline-flex items-center gap-1.5 font-medium text-brand-700"><Phone className="size-4" />{view.business.phone}</a>}
        </div>
      )}
      <Link href={`/login?next=${encodeURIComponent(next)}`} className="flex items-center justify-center gap-1.5 py-2 text-xs text-ink-500 hover:text-ink-800">
        <LockKeyhole className="size-3.5" />{t("Staff: log in to see stock and sales")}
      </Link>
    </article>
  );
}

function Staff({ view }: { view: StaffView }) {
  const t = useT();
  const { product: p, sections: s } = view;
  const low = s.stock && s.stock.reorder_level > 0 && s.stock.total <= s.stock.reorder_level;
  return (
    <article className="space-y-4">
      <div className="flex gap-3 rounded-xl bg-surface p-4 ring-1 ring-ink-100">
        {/* eslint-disable-next-line @next/next/no-img-element -- served by the API storage */}
        {p.image_url ? <img src={p.image_url} alt="" className="size-16 rounded-lg object-cover" /> : <span className="grid size-16 place-items-center rounded-lg bg-ink-100"><Tag className="size-6 text-ink-400" /></span>}
        <div className="min-w-0">
          <h1 className="text-lg font-semibold leading-tight text-ink-900">{p.name}</h1>
          <p className="text-xs text-ink-500">{[p.sku, p.barcode, p.category].filter(Boolean).join(" · ")}</p>
          <p className="num mt-1 text-lg font-bold text-brand-700">{money(p.selling_price)}</p>
          {p.wholesale_price && <p className="text-xs text-ink-600">{t("Wholesale {price} from {qty}", { price: money(p.wholesale_price), qty: qty(p.wholesale_min_qty ?? 0, p.unit) })}</p>}
          {!p.is_active && <p className="mt-1 text-xs font-medium text-ember-700">{t("Inactive: not sold at the till")}</p>}
        </div>
      </div>

      {s.stock && (
        <section className="rounded-xl bg-surface p-4 ring-1 ring-ink-100">
          <h2 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-ink-900"><Boxes className="size-4" />{t("Stock")}</h2>
          <p className={cn("num text-2xl font-bold", s.stock.total <= 0 ? "text-ember-600" : low ? "text-saffron-700" : "text-ink-900")}>{qty(s.stock.total, s.stock.unit)}</p>
          {s.stock.reorder_level > 0 && <p className="text-xs text-ink-500">{t("Reorder at {qty}", { qty: qty(s.stock.reorder_level, s.stock.unit) })}</p>}
          <ul className="mt-3 divide-y divide-ink-100 text-sm">
            {s.stock.branches.map((b) => <li key={b.branch} className="flex justify-between py-1.5"><span className="text-ink-700">{b.branch}</span><span className="num font-medium">{qty(b.quantity, s.stock!.unit)}</span></li>)}
          </ul>
          {s.stock.next_expiry && <p className="mt-2 text-xs text-ink-600">{t("Next expiry: {date} ({qty})", { date: date(s.stock.next_expiry.date), qty: qty(s.stock.next_expiry.quantity, s.stock.unit) })}</p>}
        </section>
      )}

      {s.sales && (
        <section className="rounded-xl bg-surface p-4 ring-1 ring-ink-100">
          <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-ink-900"><BarChart3 className="size-4" />{t("Sales")}</h2>
          <div className="grid grid-cols-3 gap-2 text-center">
            {([["Today", s.sales.today], ["7 days", s.sales.days_7], ["30 days", s.sales.days_30]] as const).map(([k, v]) => (
              <div key={k} className="rounded-lg bg-ink-50 px-2 py-2.5">
                <p className="text-[11px] text-ink-500">{t(k)}</p>
                <p className="num text-sm font-semibold text-ink-900">{qty(v.quantity, p.unit)}</p>
                <p className="num text-[11px] text-ink-600">{money(v.amount)}</p>
              </div>
            ))}
          </div>
          <p className="mt-2 text-xs text-ink-500">{s.sales.last_sold_at ? t("Last sold {when}", { when: dateTime(s.sales.last_sold_at) }) : t("Not sold yet")}</p>
        </section>
      )}

      {s.cost && (
        <section className="flex justify-between rounded-xl bg-surface p-4 text-sm ring-1 ring-ink-100">
          <span><span className="block text-xs text-ink-500">{t("Buying price")}</span><span className="num font-semibold">{money(s.cost.cost_price)}</span></span>
          <span className="text-right"><span className="block text-xs text-ink-500">{t("Margin")}</span><span className="num font-semibold">{s.cost.margin_percent === null ? "—" : `${s.cost.margin_percent}%`}</span></span>
        </section>
      )}

      {view.actions.length > 0 && (
        <div className="grid grid-cols-2 gap-2">
          {view.actions.map((a) => {
            const A = ACTIONS[a.key];
            return <Link key={a.key} href={a.url} className={cn("flex items-center justify-center gap-1.5 rounded-lg px-3 py-2.5 text-sm font-medium", a.key === "sell" ? "bg-brand-600 text-white hover:bg-brand-700" : "bg-surface text-ink-800 ring-1 ring-ink-200 hover:bg-ink-50")}><A.icon className="size-4" />{t(A.label)}</Link>;
          })}
        </div>
      )}
    </article>
  );
}
