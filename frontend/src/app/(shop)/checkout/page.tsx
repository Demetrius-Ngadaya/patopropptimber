"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Banknote, Bike, Smartphone, Store } from "lucide-react";
import { isTzMobile } from "@/features/payments/types";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useCart } from "@/features/shop/cart";
import { cn, money, qty, today } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import { Button, EmptyState, Field, Input, Select, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { PickerMap, toLatLng, UseMyLocation, type LatLng } from "@/components/map";

type ShopBranch = { id: number; name: string; location: string | null; address: string | null; phone: string | null };
type Profile = { profile: { phone: string; address: string | null; city: string | null } };

export default function CheckoutPage() {
  const t = useT();
  const router = useRouter();
  const { user } = useAuth();
  const { settings } = usePublicSettings();
  const { items, subtotal, branchId, setBranchId, clear } = useCart();
  const branches = useApi<ApiResponse<ShopBranch[]>>("shop/branches");
  const profile = useApi<ApiResponse<Profile>>(user?.type === "customer" ? "shop/account" : null);
  const [method, setMethod] = useState<"pickup" | "delivery">("pickup");
  const [address, setAddress] = useState("");
  const [phone, setPhone] = useState("");
  const [date, setDate] = useState("");
  const [notes, setNotes] = useState("");
  const [payNow, setPayNow] = useState(false);
  const [payPhone, setPayPhone] = useState("");
  const cur = settings?.currency ?? "";
  const deliveryOn = Boolean(settings?.shop_delivery_enabled);
  const byDistance = Number(settings?.delivery_fee_per_km ?? 0) > 0;
  const [pin, setPin] = useState<LatLng | null>(null);
  const [quote, setQuote] = useState<{ fee: number; distance_km: number | null } | null>(null);
  const [quoteError, setQuoteError] = useState<string | null>(null);
  const fee = method === "delivery" ? (quote?.fee ?? Number(settings?.shop_delivery_fee ?? 0)) : 0;
  const shop = toLatLng(settings?.business_latitude, settings?.business_longitude);
  const onlineOn = Boolean(settings?.online_payments_shop);
  const paying = onlineOn && payNow;

  // The fee depends on how far the pin is from the shop: ask the server each time it moves.
  useEffect(() => {
    if (method !== "delivery" || !pin || !branchId) {
      setQuote(null);
      setQuoteError(null);
      return;
    }
    let live = true;
    api.post<ApiResponse<{ fee: number; distance_km: number | null }>>("shop/delivery-quote", { branch_id: branchId, latitude: pin.lat, longitude: pin.lng })
      .then((r) => { if (live) { setQuote(r.data); setQuoteError(null); } })
      .catch((e) => { if (live) { setQuote(null); setQuoteError(e instanceof ApiError ? e.message : t("Could not work out the delivery fee.")); } });
    return () => { live = false; };
  }, [method, pin, branchId, t]);

  useEffect(() => {
    const p = profile.data?.data.profile;
    if (p) {
      setPhone((v) => v || p.phone || "");
      setPayPhone((v) => v || p.phone || "");
      setAddress((v) => v || [p.address, p.city].filter(Boolean).join(", "));
    }
  }, [profile.data]);
  useEffect(() => {
    const list = branches.data?.data;
    if (list?.length && (branchId === null || !list.some((b) => b.id === branchId))) setBranchId(list[0].id);
  }, [branches.data, branchId, setBranchId]);

  const place = useAction(() => api.post<ApiResponse<{ number: string }>>("shop/account/orders", {
    branch_id: branchId,
    items: items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })),
    delivery_method: method,
    delivery_address: method === "delivery" ? address : null,
    delivery_latitude: method === "delivery" ? pin?.lat ?? null : null,
    delivery_longitude: method === "delivery" ? pin?.lng ?? null : null,
    contact_phone: phone || null,
    preferred_date: date || null,
    customer_notes: notes || null,
    pay_online: paying || undefined,
    payment_phone: paying ? payPhone : undefined,
  }), { invalidate: [["shop/account/orders"]], onSuccess: (res) => { clear(); router.replace(`/account/orders/${encodeURIComponent(res.data.number)}?placed=1${paying ? "&pay=1" : ""}`); } });
  const errors = fieldErrors(place.error);

  if (!items.length && !place.isSuccess) return <EmptyState title={t("Your basket is empty")} message={t("Add some products before checking out.")} action={<Link href="/" className="font-medium text-brand-700 hover:underline">{t("Go to the shop")}</Link>} />;

  const branch = branches.data?.data.find((b) => b.id === branchId);
  const option = (value: "pickup" | "delivery", icon: React.ReactNode, title: string, sub: string) => (
    <label className={cn("flex flex-1 cursor-pointer items-start gap-3 rounded-lg p-4 ring-1 transition-colors", method === value ? "bg-brand-50/60 ring-2 ring-brand-600" : "bg-surface ring-ink-200 hover:ring-ink-400")}>
      <input type="radio" name="method" className="sr-only" checked={method === value} onChange={() => setMethod(value)} />
      <span className="mt-0.5 text-brand-700">{icon}</span>
      <span><span className="block font-medium text-ink-900">{t(title)}</span><span className="text-sm text-ink-600">{sub}</span></span>
    </label>
  );

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
      <section className="space-y-6">
        <h1 className="text-2xl font-semibold text-ink-950">{t("Checkout")}</h1>
        <div className="space-y-4 rounded-lg bg-surface p-5 ring-1 ring-ink-200/70">
          <h2 className="font-semibold text-ink-900">{t("How do you want to get your order?")}</h2>
          <div className="flex flex-col gap-3 sm:flex-row">
            {option("pickup", <Store className="size-5" />, "Collect from the shop", t("Free. We tell you when it is ready."))}
            {deliveryOn && option("delivery", <Bike className="size-5" />, "Deliver to me", byDistance ? t("Fee depends on the distance") : Number(settings?.shop_delivery_fee) ? t("{fee} delivery fee", { fee: `${cur} ${money(settings?.shop_delivery_fee)}` }) : t("Free delivery"))}
          </div>
          {(branches.data?.data.length ?? 0) > 1 && (
            <Field label={method === "pickup" ? t("Collect from") : t("Send from")} error={errors.branch_id}>{(p) => (
              <Select {...p} value={branchId ?? ""} onChange={(e) => setBranchId(Number(e.target.value))}>{branches.data!.data.map((b) => <option key={b.id} value={b.id}>{b.name}{b.location ? ` — ${b.location}` : ""}</option>)}</Select>
            )}</Field>
          )}
          {method === "pickup" && branch?.address && <p className="text-sm text-ink-600">{branch.name}: {branch.address}{branch.phone ? ` · ${branch.phone}` : ""}</p>}
          {method === "delivery" && (
            <div className="space-y-2">
              <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                  <p className="text-[13px] font-medium text-ink-700">{t("Where should we bring it?")}{byDistance && <span className="ml-0.5 text-ember-600">*</span>}</p>
                  <p className="text-xs text-ink-500">{t("Tap the map at your door, or use your location. The rider sees this exact spot.")}</p>
                </div>
                <UseMyLocation onLocated={setPin} />
              </div>
              <PickerMap value={pin} onChange={setPin} center={shop} others={shop ? [{ ...shop, kind: "shop", label: settings?.business_name }] : []} height={280} />
              {quote && pin && (
                <p className="text-sm text-brand-800">
                  {quote.distance_km !== null ? t("About {km} km from the shop · delivery {fee}", { km: quote.distance_km, fee: `${cur} ${money(quote.fee)}` }) : t("Delivery {fee}", { fee: `${cur} ${money(quote.fee)}` })}
                </p>
              )}
              {quoteError && <p className="text-sm text-ember-600">{quoteError}</p>}
              {(errors.delivery_latitude || errors.delivery_longitude) && <p className="text-sm text-ember-600">{errors.delivery_latitude ?? errors.delivery_longitude}</p>}
            </div>
          )}
          {method === "delivery" && <Field label={t("Delivery address")} required error={errors.delivery_address} hint={t("Street, area and a landmark help the rider find you.")}>{(p) => <Textarea {...p} rows={2} value={address} onChange={(e) => setAddress(e.target.value)} />}</Field>}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t("Phone number for this order")} error={errors.contact_phone}>{(p) => <Input {...p} type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />}</Field>
            <Field label={t("Preferred day (optional)")} error={errors.preferred_date}>{(p) => <Input {...p} type="date" min={today()} value={date} onChange={(e) => setDate(e.target.value)} />}</Field>
          </div>
          <Field label={t("Notes for the shop (optional)")} error={errors.customer_notes}>{(p) => <Textarea {...p} rows={2} maxLength={1000} placeholder={t("e.g. cut into 1 kg packs, call on arrival")} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
        </div>
        {onlineOn ? (
          <div className="space-y-4 rounded-lg bg-surface p-5 ring-1 ring-ink-200/70">
            <h2 className="font-semibold text-ink-900">{t("How do you want to pay?")}</h2>
            <div className="flex flex-col gap-3 sm:flex-row">
              <label className={cn("flex flex-1 cursor-pointer items-start gap-3 rounded-lg p-4 ring-1 transition-colors", payNow ? "bg-brand-50/60 ring-2 ring-brand-600" : "bg-surface ring-ink-200 hover:ring-ink-400")}>
                <input type="radio" name="pay" className="sr-only" checked={payNow} onChange={() => setPayNow(true)} />
                <span className="mt-0.5 text-brand-700"><Smartphone className="size-5" /></span>
                <span><span className="block font-medium text-ink-900">{t("Pay now with mobile money")}</span><span className="text-sm text-ink-600">{t("M-Pesa, Airtel Money, Mixx by Yas or HaloPesa: confirm with your PIN.")}</span></span>
              </label>
              <label className={cn("flex flex-1 cursor-pointer items-start gap-3 rounded-lg p-4 ring-1 transition-colors", !payNow ? "bg-brand-50/60 ring-2 ring-brand-600" : "bg-surface ring-ink-200 hover:ring-ink-400")}>
                <input type="radio" name="pay" className="sr-only" checked={!payNow} onChange={() => setPayNow(false)} />
                <span className="mt-0.5 text-brand-700"><Banknote className="size-5" /></span>
                <span><span className="block font-medium text-ink-900">{method === "delivery" ? t("Pay when it arrives") : t("Pay when you collect")}</span><span className="text-sm text-ink-600">{t("Cash or mobile money at the shop.")}</span></span>
              </label>
            </div>
            {payNow && (
              <Field label={t("Mobile money number that will pay")} required error={errors.payment_phone} hint={t("After you place the order, a prompt appears on this phone. Enter your PIN to pay.")}>
                {(p) => <Input {...p} type="tel" inputMode="tel" placeholder="0712 345 678" value={payPhone} onChange={(e) => setPayPhone(e.target.value)} />}
              </Field>
            )}
          </div>
        ) : (
          <p className="rounded-lg bg-saffron-50 px-4 py-3 text-sm text-saffron-700">{t("No payment is taken online. You pay")} {method === "delivery" ? t("when your order arrives") : t("when you collect")}{t(", with cash or mobile money.")}</p>
        )}
      </section>

      <aside className="lg:pt-12">
        <div className="rounded-lg bg-surface p-5 ring-1 ring-ink-200/70 lg:sticky lg:top-24">
          <h2 className="mb-3 font-semibold text-ink-900">{t("Order summary")}</h2>
          <ul className="max-h-72 space-y-2 overflow-y-auto text-sm">
            {items.map((i) => (
              <li key={i.product_id} className="flex justify-between gap-3">
                <span className="min-w-0"><span className="block truncate">{i.name}</span><span className="num text-ink-500">{qty(i.quantity, i.unit)} × {money(i.price)}</span></span>
                <span className="num shrink-0">{money(i.price * i.quantity)}</span>
              </li>
            ))}
          </ul>
          <dl className="mt-4 space-y-1.5 border-t border-ink-100 pt-3 text-sm">
            <div className="flex justify-between"><dt className="text-ink-600">{t("Subtotal")}</dt><dd className="num">{money(subtotal)}</dd></div>
            {method === "delivery" && <div className="flex justify-between"><dt className="text-ink-600">{t("Delivery")}</dt><dd className="num">{byDistance && !quote ? t("Pin your place") : money(fee)}</dd></div>}
            <div className="flex justify-between pt-1 text-lg font-semibold"><dt>{t("Total")}</dt><dd className="num">{cur} {money(subtotal + fee)}</dd></div>
          </dl>
          {errors.items && <p className="mt-3 text-sm text-ember-600">{errors.items}</p>}
          <Button size="lg" className="mt-4 w-full" loading={place.isPending} disabled={!branchId || (method === "delivery" && (!address.trim() || Boolean(quoteError) || (byDistance && !pin))) || (paying && !isTzMobile(payPhone))} onClick={() => place.mutate(undefined)}>{paying ? t("Place order and pay") : t("Place order")}</Button>
          <p className="mt-2 text-center text-xs text-ink-500">{t("Prices and stock are confirmed by the shop when you place the order.")}</p>
        </div>
      </aside>
    </div>
  );
}
