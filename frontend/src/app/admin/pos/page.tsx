"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { BadgePercent, CheckCircle2, Minus, PauseCircle, Plus, Printer, RotateCcw, ShoppingBasket, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, qty, toNumber, cn, dateTime } from "@/lib/format";
import { useAction, useApi, useUrlParam } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import type { CartLine, Customer, Product, Sale } from "@/lib/types";
import { Guard, Can } from "@/components/layout/Guard";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { CustomerPicker } from "@/features/crm/components/CustomerPicker";
import { PaymentModal, type CheckoutExtras } from "@/features/sales/components/PaymentModal";
import { Badge, Button, EmptyState, Input, Modal, NumericInput, Skeleton } from "@/components/ui";
import { usePublicSettings } from "@/lib/public-settings";
import { useT } from "@/lib/i18n";

type Quote = {
  price_level: string;
  total: number;
  subtotal: number;
  discount: number;
  tax: number;
  promotion: { id: number; name: string; discount: number } | null;
  lines: Array<{ index: number; product_unit_id: number | null; list_price: number; unit_price: number; price_level: string; line_total: number }>;
};

type EligiblePromotion = { id: number; code: string; name: string; headline: string; min_purchase: string | null; ends_on: string };

type HeldSale = { id: number; label: string | null; total: string; created_at: string; cart: CartLine[]; customer?: Customer | null; user?: { name: string } };

const cartKey = (branch: string | null) => `mz_cart_${branch ?? "x"}`;

function lineTotal(l: CartLine) {
  return Math.max(0, l.quantity * l.unit_price - l.discount);
}

const keyOf = (productId: number, unitId?: number | null) => `${productId}:${unitId ?? 0}`;

/** Older saved / held carts had no key: give them one. */
const withKeys = (lines: CartLine[]): CartLine[] => lines.map((l) => ({ ...l, key: l.key ?? keyOf(l.product_id, l.product_unit_id), discount: l.discount ?? 0 }));

/** The cart as the server sees it: changes here (not price updates) trigger a new quote. */
const signature = (cart: CartLine[], customerId: number | undefined, level: string, discount: number, promotionId: number | null) =>
  JSON.stringify([cart.map((l) => [l.key, l.quantity, l.discount, l.manual ? l.unit_price : null]), customerId ?? null, level, discount, promotionId]);

function PosScreen() {
  const t = useT();
  const { can, branchId, user } = useAuth();
  const [cart, setCart] = useState<CartLine[]>([]);
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [walkInName, setWalkInName] = useState("");
  const [invoiceDiscount, setInvoiceDiscount] = useState(0);
  const [promotion, setPromotion] = useState<EligiblePromotion | null>(null);
  const [paying, setPaying] = useState(false);
  const [done, setDone] = useState<Sale | null>(null);
  const [showHeld, setShowHeld] = useState(false);
  const [heldId, setHeldId] = useState<number | null>(null);
  const [category, setCategory] = useState<number | null>(null);
  const [level, setLevel] = useState<"auto" | "retail" | "wholesale">("auto");
  const [quote, setQuote] = useState<{ sig: string; data: Quote } | null>(null);
  const [quoting, setQuoting] = useState(false);
  const loaded = useRef(false);

  const { categories = [] } = useLookups(["categories"]);
  const { settings: posSettings } = usePublicSettings();
  const showCategories = posSettings?.pos_show_categories !== false;
  // Without a category: the products this branch sells most. Anything else is found with the search box.
  const quick = useApi<ApiResponse<Product[]>>(branchId && branchId !== "all" ? "admin/pos/products" : null, { category_id: showCategories ? category : null, limit: 24 });
  const held = useApi<ApiResponse<HeldSale[]>>(showHeld ? "admin/pos/held" : null);

  // Keep the cart through page reloads (per branch).
  useEffect(() => {
    try {
      const saved = localStorage.getItem(cartKey(branchId));
      if (saved) {
        const parsed = JSON.parse(saved);
        setCart(withKeys(parsed.cart ?? []));
        setCustomer(parsed.customer ?? null);
      } else {
        setCart([]);
        setCustomer(null);
      }
    } catch {
      /* ignore */
    }
    loaded.current = true;
  }, [branchId]);

  useEffect(() => {
    if (!loaded.current) return;
    try {
      localStorage.setItem(cartKey(branchId), JSON.stringify({ cart, customer }));
    } catch {
      /* storage full or blocked */
    }
  }, [cart, customer, branchId]);

  const add = useCallback(
    (p: Product) => {
      const unit = p.matched_unit_id ? p.units?.find((u) => u.id === p.matched_unit_id) ?? null : null;
      const factor = unit ? toNumber(unit.factor) : 1;
      const stock = p.is_service ? null : toNumber(p.stock_quantity);
      const key = keyOf(p.id, unit?.id);
      setCart((c) => {
        if (c.some((l) => l.key === key)) {
          return c.map((l) => (l.key === key ? { ...l, quantity: Number((l.quantity + 1).toFixed(3)) } : l));
        }
        const price = unit ? toNumber(unit.retail_price) : toNumber(p.selling_price);
        return [
          ...c,
          {
            key, product_id: p.id, product_unit_id: unit?.id ?? null, units: p.units?.filter((u) => u.is_active !== false) ?? [], factor,
            name: p.name, unit: unit?.name ?? p.unit?.short_name, base_unit: p.unit?.short_name, allow_decimal: unit ? false : p.allow_decimal,
            list_price: price, unit_price: price, quantity: 1, discount: 0, available: stock === null ? null : stock / factor, is_service: p.is_service,
          },
        ];
      });
      if (stock !== null && stock <= 0) toast.warning(t("{name} shows no stock in this branch.", { name: p.name }));
    },
    [t],
  );

  // Opened from a scanned QR code ("Sell"): put that product in the cart.
  useUrlParam("code", (code) => {
    api.get<ApiResponse<Product>>("admin/pos/lookup", { code }).then((r) => add(r.data)).catch(() => toast.error(t("No product found with barcode / SKU \"{code}\".", { code })));
  });

  /** Switch a line between the base unit and a pack (carton, dozen ...). */
  const changeUnit = (line: CartLine, unitId: number | null) => {
    const unit = unitId ? line.units?.find((u) => u.id === unitId) ?? null : null;
    const factor = unit ? toNumber(unit.factor) : 1;
    const baseStock = line.available === null || line.available === undefined ? null : line.available * (line.factor ?? 1);
    const key = keyOf(line.product_id, unitId);
    setCart((c) => {
      const merged = c.find((l) => l.key === key && l.key !== line.key);
      const rest = c.filter((l) => l.key !== line.key);
      if (merged) return rest.map((l) => (l.key === key ? { ...l, quantity: l.quantity + line.quantity } : l));
      const price = unit ? toNumber(unit.retail_price) : line.list_price / (line.factor ?? 1);
      const next: CartLine = {
        ...line, key, product_unit_id: unitId, factor, unit: unit?.name ?? line.base_unit, allow_decimal: unit ? false : line.allow_decimal,
        list_price: price, unit_price: price, manual: false, available: baseStock === null ? null : baseStock / factor,
      };
      return c.map((l) => (l.key === line.key ? next : l));
    });
  };

  const update = (key: string, patch: Partial<CartLine>) => setCart((c) => c.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const remove = (key: string) => setCart((c) => c.filter((l) => l.key !== key));

  // Ask the server for exact prices (packs, wholesale, tax) whenever the cart changes.
  // Running promotions this customer (or a walk-in) may use.
  const eligible = useApi<ApiResponse<EligiblePromotion[]>>(can(["marketing.apply", "marketing.promotions"]) ? "admin/promotions/eligible" : null, { customer_id: customer?.id });
  const offers = eligible.data?.data ?? [];
  // A different customer may not qualify for the promotion already chosen.
  useEffect(() => {
    if (promotion && eligible.data && !offers.some((o) => o.id === promotion.id)) setPromotion(null);
  }, [eligible.data]); // eslint-disable-line react-hooks/exhaustive-deps

  const sig = signature(cart, customer?.id, level, invoiceDiscount, promotion?.id ?? null);
  useEffect(() => {
    if (!cart.length) {
      setQuote(null);
      return;
    }
    if (quote?.sig === sig) return;
    const controller = new AbortController();
    setQuoting(true);
    const timer = setTimeout(async () => {
      try {
        const res = await api.post<ApiResponse<Quote>>("admin/pos/quote", {
          customer_id: customer?.id,
          price_level: level === "auto" ? undefined : level,
          discount: invoiceDiscount || undefined,
          promotion_id: promotion?.id,
          items: cart.map((l) => ({ product_id: l.product_id, product_unit_id: l.product_unit_id ?? undefined, quantity: l.quantity, discount: l.discount || undefined, unit_price: l.manual ? l.unit_price : undefined })),
        });
        if (controller.signal.aborted) return;
        setCart((c) => c.map((l, i) => {
          const q = res.data.lines.find((x) => x.index === i);
          return q ? { ...l, list_price: q.list_price, unit_price: l.manual ? l.unit_price : q.unit_price, price_level: q.price_level } : l;
        }));
        setQuote({ sig, data: res.data });
      } catch (e) {
        // The promotion does not fit this cart (e.g. below its minimum): drop it and say why.
        if (promotion && e instanceof ApiError && e.status === 422 && !controller.signal.aborted) {
          toast.error(e.message);
          setPromotion(null);
        }
        /* offline: the cart keeps the local estimate; checkout re-prices on the server */
      } finally {
        if (!controller.signal.aborted) setQuoting(false);
      }
    }, 250);
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sig]);

  const totals = useMemo(() => {
    const subtotal = cart.reduce((s, l) => s + l.quantity * l.unit_price, 0);
    const lineDiscounts = cart.reduce((s, l) => s + l.discount, 0);
    const synced = quote?.sig === sig;
    const total = synced ? quote!.data.total : Math.max(0, subtotal - lineDiscounts - invoiceDiscount);
    const tax = synced ? quote!.data.tax : 0;
    const items = cart.reduce((s, l) => s + l.quantity, 0);
    const promotionDiscount = synced ? quote!.data.promotion?.discount ?? 0 : 0;
    return { subtotal, lineDiscounts, total, tax, items, synced, promotionDiscount };
  }, [cart, invoiceDiscount, quote, sig]);

  const reset = () => {
    setCart([]);
    setCustomer(null);
    setWalkInName("");
    setInvoiceDiscount(0);
    setPromotion(null);
    setHeldId(null);
    setLevel("auto");
  };

  const checkout = useAction(
    (data: CheckoutExtras) =>
      api.post<ApiResponse<Sale>>("admin/pos/checkout", {
        customer_id: customer?.id,
        customer_name: customer ? undefined : walkInName || undefined,
        items: cart.map((l) => ({
          product_id: l.product_id,
          product_unit_id: l.product_unit_id ?? undefined,
          quantity: l.quantity,
          unit_price: l.manual ? l.unit_price : undefined,
          discount: l.discount || undefined,
        })),
        price_level: level === "auto" ? undefined : level,
        discount: invoiceDiscount || undefined,
        promotion_id: promotion?.id,
        held_sale_id: heldId ?? undefined,
        ...data,
      }),
    {
      invalidate: [["admin/pos/products"], ["admin/pos/held"]],
      onSuccess: (res) => {
        setPaying(false);
        setDone(res.data);
        reset();
      },
    },
  );

  const hold = useAction(
    () => api.post("admin/pos/held", { cart, customer_id: customer?.id, label: customer?.name ?? (walkInName || undefined), total: totals.total }),
    { invalidate: [["admin/pos/held"]], onSuccess: reset },
  );
  const discard = useAction((id: number) => api.del(`admin/pos/held/${id}`), { invalidate: [["admin/pos/held"]] });

  const resume = (h: HeldSale) => {
    if (cart.length && !confirm(t("Replace the current cart with this held sale?"))) return;
    setCart(withKeys(h.cart));
    setCustomer(h.customer ?? null);
    setHeldId(h.id);
    setShowHeld(false);
  };

  // Keyboard: F2 search, F9 pay
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "F2") {
        e.preventDefault();
        document.querySelector<HTMLInputElement>("[data-product-search]")?.focus();
      }
      if (e.key === "F9" && cart.length && !paying && !quoting) {
        e.preventDefault();
        setPaying(true);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [cart.length, paying, quoting]);

  if (branchId === "all") {
    return (
      <EmptyState
        icon={<ShoppingBasket className="size-10" />}
        title={t("Choose a branch to sell from")}
        message={t("You are viewing all branches. Pick the branch you are selling in from the branch menu at the top of the page.")}
      />
    );
  }

  const canPrice = can("pos.change_price");
  const canDiscount = can("pos.discount");

  return (
    <div className="grid gap-5 xl:grid-cols-[1fr_420px]">
      {/* Products */}
      <section aria-label={t("Products")} className="min-w-0">
        <div className="mb-3 flex items-center justify-between gap-3">
          <h1 className="text-[22px] font-semibold tracking-tight">{t("Point of sale")}</h1>
          <div className="flex items-center gap-2">
            <Can perm="pos.hold">
              <Button variant="secondary" size="sm" icon={<RotateCcw className="size-4" />} onClick={() => setShowHeld(true)}>{t("Held sales")}</Button>
            </Can>
          </div>
        </div>
        <ProductPicker onSelect={add} autoFocus />
        <p className="mt-1.5 text-xs text-ink-500">{t("Scan with a barcode scanner, type to search, or tap a product. F2 jumps here, F9 opens payment.")}</p>

        {showCategories && <div className="mt-4 flex gap-2 overflow-x-auto pb-1">
          <button type="button" onClick={() => setCategory(null)} className={cn("whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-medium", category === null ? "bg-night-900 text-white" : "bg-surface text-ink-700 ring-1 ring-ink-200 hover:bg-ink-50")}>
            {t("Frequently sold")}
          </button>
          {categories.map((c) => (
            <button key={c.id} type="button" onClick={() => setCategory(c.id)} className={cn("whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-medium", category === c.id ? "bg-night-900 text-white" : "bg-surface text-ink-700 ring-1 ring-ink-200 hover:bg-ink-50")}>
              {c.name}
            </button>
          ))}
        </div>}
        {(!showCategories || category === null) && <p className="mt-4 text-xs font-medium uppercase tracking-wide text-ink-500">{t("Frequently sold here")}</p>}

        {quick.isLoading && !quick.data && (
          <div role="status" aria-busy="true" className="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
            {Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-24 rounded-[var(--radius-panel)]" />)}
          </div>
        )}
        <div className="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
          {(quick.data?.data ?? []).map((p) => {
            const stock = toNumber(p.stock_quantity);
            const out = !p.is_service && stock <= 0;
            return (
              <button
                key={p.id}
                type="button"
                onClick={() => add(p)}
                className={cn("flex min-h-24 flex-col justify-between rounded-[var(--radius-panel)] bg-surface p-3 text-left ring-1 ring-ink-200/80 transition hover:ring-brand-500 focus-visible:ring-2", out && "opacity-60")}
              >
                <span className="line-clamp-2 text-sm font-medium leading-snug text-ink-900">{p.name}</span>
                <span className="mt-2 flex items-end justify-between gap-1">
                  <span className="num text-[15px] font-semibold text-brand-700">{money(p.selling_price)}</span>
                  {!p.is_service && <span className={cn("num text-xs", out ? "text-ember-600" : "text-ink-500")}>{out ? t("none") : qty(stock, p.unit?.short_name)}</span>}
                </span>
              </button>
            );
          })}
        </div>
      </section>

      {/* Cart as a till receipt */}
      <section aria-label={t("Cart")} className="xl:sticky xl:top-20 xl:self-start">
        <div className="receipt-tape rounded-t-[var(--radius-panel)] shadow-[0_1px_0_rgba(19,33,59,0.06),0_12px_30px_-12px_rgba(19,33,59,0.25)]">
          <div className="border-b border-dashed border-ink-200 px-4 pb-3 pt-4">
            <div className="mb-2 flex items-center justify-between">
              <p className="text-sm font-semibold text-ink-900">{t("Current sale")} {heldId && <Badge tone="saffron" className="ml-1">{t("resumed")}</Badge>}</p>
              {cart.length > 0 && (
                <button type="button" onClick={() => confirm(t("Clear the cart?")) && reset()} className="text-xs font-medium text-ember-600 hover:underline">{t("Clear")}</button>
              )}
            </div>
            <CustomerPicker value={customer} onChange={setCustomer} />
            {(can("pos.wholesale") || customer?.customer_type === "wholesale") && (
              <div className="mt-2 flex items-center gap-1 rounded-md bg-ink-50 p-0.5 text-xs" role="radiogroup" aria-label={t("Price level")}>
                {(["auto", "retail", "wholesale"] as const).map((v) => (
                  <button
                    key={v}
                    type="button"
                    role="radio"
                    aria-checked={level === v}
                    disabled={v === "wholesale" && !can("pos.wholesale") && customer?.customer_type !== "wholesale"}
                    onClick={() => setLevel(v)}
                    className={cn("flex-1 rounded px-2 py-1 font-medium", level === v ? "bg-surface text-ink-900 shadow-sm" : "text-ink-500 hover:text-ink-800")}
                  >
                    {v === "auto" ? t("Auto price") : v === "retail" ? t("Retail") : t("Wholesale")}
                  </button>
                ))}
              </div>
            )}
            {!customer && (
              <Input className="mt-2 h-9" placeholder={t("Customer name (optional)")} value={walkInName} onChange={(e) => setWalkInName(e.target.value)} aria-label={t("Customer name")} />
            )}
          </div>

          <ul className="max-h-[46vh] divide-y divide-dashed divide-ink-200 overflow-y-auto">
            {cart.length === 0 && (
              <li className="px-4 py-10 text-center text-sm text-ink-500">{t("Scan or pick a product to start a sale.")}</li>
            )}
            {cart.map((l) => {
              const over = l.available !== null && l.available !== undefined && !l.is_service && l.quantity > l.available;
              const step = l.allow_decimal ? 0.25 : 1;
              return (
                <li key={l.key} className="px-4 py-3">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-sm font-medium leading-snug text-ink-900">{l.name}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        {(l.units?.length ?? 0) > 0 && (
                          <select
                            aria-label={t("Selling unit for {name}", { name: l.name })}
                            value={l.product_unit_id ?? ""}
                            onChange={(e) => changeUnit(l, e.target.value ? Number(e.target.value) : null)}
                            className="h-7 rounded-md border border-ink-200 bg-surface px-1.5 text-xs text-ink-700"
                          >
                            <option value="">{t("Piece")}{l.base_unit ? ` (${l.base_unit})` : ""}</option>
                            {l.units!.map((u) => <option key={u.id} value={u.id}>{u.name} × {qty(u.factor)}</option>)}
                          </select>
                        )}
                        {l.price_level === "wholesale" && <Badge tone="blue">{t("Wholesale")}</Badge>}
                      </div>
                    </div>
                    <button type="button" onClick={() => remove(l.key)} className="rounded p-0.5 text-ink-400 hover:text-ember-600" aria-label={t("Remove {name}", { name: l.name })}>
                      <X className="size-4" />
                    </button>
                  </div>
                  <div className="mt-2 flex items-center justify-between gap-2">
                    <div className="flex items-center rounded-md ring-1 ring-ink-200">
                      <button type="button" className="p-1.5 text-ink-600 hover:bg-ink-50" aria-label={t("Less")} onClick={() => update(l.key, { quantity: Math.max(step, Number((l.quantity - step).toFixed(3))) })}>
                        <Minus className="size-3.5" />
                      </button>
                      <NumericInput
                        aria-label={t("Quantity of {name}", { name: l.name })}
                        step={l.allow_decimal ? "0.001" : "1"}
                        min={0}
                        value={l.quantity}
                        onChange={(e) => {
                          const v = Number(e.target.value);
                          update(l.key, { quantity: l.allow_decimal ? v : Math.round(v) });
                        }}
                        className="num w-20 border-0 bg-transparent text-center text-sm font-semibold focus:outline-none"
                      />
                      <button type="button" className="p-1.5 text-ink-600 hover:bg-ink-50" aria-label={t("More")} onClick={() => update(l.key, { quantity: Number((l.quantity + step).toFixed(3)) })}>
                        <Plus className="size-3.5" />
                      </button>
                    </div>
                    <span className="text-xs text-ink-500">×</span>
                    {canPrice ? (
                      <NumericInput
                        aria-label={t("Price of {name}", { name: l.name })}
                        min={0}
                        step="any"
                        value={l.unit_price}
                        onChange={(e) => update(l.key, { unit_price: Number(e.target.value), manual: true })}
                        className={cn("num w-24 rounded-md border border-ink-200 px-2 py-1 text-right text-sm", l.unit_price !== l.list_price && "border-saffron-500 bg-saffron-50")}
                      />
                    ) : (
                      <span className="num w-24 text-right text-sm">{money(l.unit_price)}</span>
                    )}
                    <span className="num w-24 text-right text-sm font-semibold">{money(lineTotal(l))}</span>
                  </div>
                  <div className="mt-1.5 flex items-center justify-between gap-2 text-xs">
                    <span className={over ? "font-medium text-ember-600" : "text-ink-500"}>
                      {l.unit && `${l.unit}. `}
                      {!l.is_service && l.available !== undefined && l.available !== null && (over ? t("Only {available} in stock", { available: qty(l.available) }) : t("{available} in stock", { available: qty(l.available) }))}
                    </span>
                    {canDiscount && (
                      <label className="flex items-center gap-1 text-ink-500">
                        {t("Discount")}
                        <NumericInput min={0} step="any" value={l.discount || ""} onChange={(e) => update(l.key, { discount: Number(e.target.value) || 0 })} className="num w-20 rounded border border-ink-200 px-1.5 py-0.5 text-right" aria-label={t("Discount on {name}", { name: l.name })} />
                      </label>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>

          <div className="space-y-1.5 border-t border-dashed border-ink-300 px-4 pt-3 text-sm">
            <div className="flex justify-between text-ink-600">
              <span>{t("Items")}</span>
              <span className="num">{qty(totals.items)}</span>
            </div>
            <div className="flex justify-between text-ink-600">
              <span>{t("Subtotal")}</span>
              <span className="num">{money(totals.subtotal)}</span>
            </div>
            {totals.lineDiscounts > 0 && (
              <div className="flex justify-between text-ink-600">
                <span>{t("Line discounts")}</span>
                <span className="num">−{money(totals.lineDiscounts)}</span>
              </div>
            )}
            {canDiscount && (
              <div className="flex items-center justify-between text-ink-600">
                <label htmlFor="invoice-discount">{t("Invoice discount")}</label>
                <NumericInput id="invoice-discount" min={0} step="any" value={invoiceDiscount || ""} onChange={(e) => setInvoiceDiscount(Number(e.target.value) || 0)} className="num w-28 rounded-md border border-ink-200 px-2 py-1 text-right" />
              </div>
            )}
            {promotion ? (
              <div className="flex items-center justify-between gap-2 rounded-md bg-brand-50 px-2 py-1 text-brand-800">
                <span className="flex min-w-0 items-center gap-1.5"><BadgePercent className="size-4 shrink-0" /><span className="truncate">{promotion.name}</span></span>
                <span className="flex items-center gap-1">
                  <span className="num">{totals.synced ? `−${money(totals.promotionDiscount)}` : "…"}</span>
                  <button type="button" onClick={() => setPromotion(null)} className="rounded p-0.5 hover:bg-brand-100" aria-label={t("Remove promotion")}><X className="size-3.5" /></button>
                </span>
              </div>
            ) : offers.length > 0 && cart.length > 0 && (
              <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-xs text-ink-500">{t("Promotions:")}</span>
                {offers.slice(0, 4).map((o) => (
                  <button key={o.id} type="button" onClick={() => setPromotion(o)} title={toNumber(o.min_purchase) > 0 ? t("Minimum purchase {amount}", { amount: money(o.min_purchase) }) : undefined}
                    className="inline-flex items-center gap-1 rounded-full bg-saffron-50 px-2 py-0.5 text-xs font-medium text-saffron-800 ring-1 ring-saffron-200 hover:bg-saffron-100">
                    <BadgePercent className="size-3.5" /> {o.name} · {o.headline}
                  </button>
                ))}
              </div>
            )}
            {totals.tax > 0 && (
              <div className="flex justify-between text-ink-600">
                <span>{t("VAT")}</span>
                <span className="num">{money(totals.tax)}</span>
              </div>
            )}
            <div className="flex items-end justify-between pt-2">
              <span className="text-sm font-semibold uppercase tracking-wide text-ink-500">{t("Total")}</span>
              <span className="num text-[34px] font-semibold leading-none tracking-tight text-ink-950">{money(totals.total)}</span>
            </div>
          </div>

          <div className="grid grid-cols-[auto_1fr] gap-2 px-4 pt-4">
            <Can perm="pos.hold">
              <Button variant="secondary" size="lg" icon={<PauseCircle className="size-5" />} disabled={!cart.length} loading={hold.isPending} onClick={() => hold.mutate(undefined)} aria-label={t("Hold sale")}>
                {t("Hold")}
              </Button>
            </Can>
            <Button size="lg" className="col-start-2 text-base" disabled={!cart.length || totals.total < 0 || quoting} loading={quoting && cart.length > 0} onClick={() => setPaying(true)}>
              {t("Pay")} {cart.length > 0 && <span className="num">{money(totals.total)}</span>}
            </Button>
          </div>
        </div>
        <p className="mt-3 text-center text-xs text-ink-500">{t("Cashier: {name}", { name: user?.name })}</p>
      </section>

      <PaymentModal
        open={paying}
        onClose={() => setPaying(false)}
        total={Number(totals.total.toFixed(2))}
        customer={customer}
        loading={checkout.isPending}
        onConfirm={(data) => checkout.mutate(data)}
      />

      <Modal
        open={Boolean(done)}
        onClose={() => setDone(null)}
        title={t("Sale completed")}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setDone(null)} data-autofocus>{t("New sale")}</Button>
            <Button icon={<Printer className="size-4" />} onClick={() => done && window.open(`/receipt/${done.id}`, "_blank", "noopener,width=420,height=700")}>
              {t("Print receipt")}
            </Button>
          </>
        }
      >
        {done && (
          <div className="text-center">
            <CheckCircle2 className="mx-auto size-12 text-brand-600" aria-hidden />
            <p className="mt-2 text-sm text-ink-600">{t("Receipt {number}", { number: done.number })}</p>
            <p className="num mt-1 text-3xl font-semibold">{money(done.total)}</p>
            {toNumber(done.change_given) > 0 && <p className="num mt-2 rounded-md bg-brand-50 py-2 text-lg font-semibold text-brand-700">{t("Change: {change_given}", { change_given: money(done.change_given) })}</p>}
            {toNumber(done.balance) > 0 && <p className="num mt-2 rounded-md bg-saffron-50 py-2 font-semibold text-saffron-700">{t("On credit: {balance}", { balance: money(done.balance) })}</p>}
            <Link href={`/admin/sales/${done.id}`} className="mt-3 inline-block text-sm font-medium text-brand-700 hover:underline">{t("View sale")}</Link>
          </div>
        )}
      </Modal>

      <Modal open={showHeld} onClose={() => setShowHeld(false)} title={t("Held sales")} description={t("Sales parked at the till. Resume one to finish it.")} size="md">
        {!held.data?.data?.length ? (
          <EmptyState title={t("No held sales")} message={t("Use Hold to park a sale and serve the next customer.")} />
        ) : (
          <ul className="divide-y divide-ink-100">
            {held.data.data.map((h) => (
              <li key={h.id} className="flex items-center justify-between gap-3 py-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{h.label ?? h.customer?.name ?? t("Customer")}</p>
                  <p className="text-xs text-ink-500">
                    {t("{count} item(s), {created_at}, by {name}", { count: h.cart.length, created_at: dateTime(h.created_at), name: h.user?.name })}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <span className="num text-sm font-semibold">{money(h.total)}</span>
                  <Button size="sm" onClick={() => resume(h)}>{t("Resume")}</Button>
                  <Button size="sm" variant="ghost" aria-label={t("Discard held sale")} onClick={() => confirm(t("Discard this held sale?")) && discard.mutate(h.id)}>
                    <Trash2 className="size-4 text-ember-600" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Modal>
    </div>
  );
}

export default function PosPage() {
  return (
    <Guard perm="pos.access">
      <PosScreen />
    </Guard>
  );
}
