"use client";

import { useEffect, useState } from "react";
import { Bike } from "lucide-react";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { cn, money } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { Button, Field, Input, Modal, Textarea } from "@/components/ui";
import { PickerMap, toLatLng, UseMyLocation, type LatLng } from "@/components/map";

type Courier = { id: number; name: string; phone: string | null; open_deliveries: number };

/**
 * Give an online order or a till sale to a delivery person. The customer's pin
 * (from checkout) is used when there is one; otherwise it can be set here.
 */
export function AssignDeliveryDialog({ open, onClose, source, address, phone, pin, fee, onAssigned }: {
  open: boolean;
  onClose: () => void;
  source: { order_id: number } | { sale_id: number };
  address?: string | null;
  phone?: string | null;
  pin?: LatLng | null;
  /** Fee already agreed (an online order's delivery fee). */
  fee?: number | null;
  onAssigned?: () => void;
}) {
  const t = useT();
  const { settings } = usePublicSettings();
  const couriers = useApi<ApiResponse<Courier[]>>(open ? "admin/deliveries/couriers" : null);
  const [courierId, setCourierId] = useState<number | null>(null);
  const [f, setF] = useState({ address: "", phone: "", note: "", fee: "" });
  const [point, setPoint] = useState<LatLng | null>(null);
  const [quote, setQuote] = useState<{ fee: number; distance_km: number | null } | null>(null);
  const [quoteError, setQuoteError] = useState<string | null>(null);
  const [wasOpen, setWasOpen] = useState(false);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setCourierId(null);
      setF({ address: address ?? "", phone: phone ?? "", note: "", fee: fee !== null && fee !== undefined ? String(fee) : "" });
      setPoint(pin ?? null);
    }
  }
  const shop = toLatLng(settings?.business_latitude, settings?.business_longitude);

  useEffect(() => {
    if (!open || !point) { setQuote(null); setQuoteError(null); return; }
    let live = true;
    api.post<ApiResponse<{ fee: number; distance_km: number | null }>>("admin/deliveries/quote", { latitude: point.lat, longitude: point.lng })
      .then((r) => { if (!live) return; setQuote(r.data); setQuoteError(null); })
      .catch((e) => live && setQuoteError(e instanceof ApiError ? e.message : null));
    return () => { live = false; };
  }, [open, point]);

  const assign = useAction(() => api.post("admin/deliveries", {
    ...source,
    courier_id: courierId,
    address: f.address || null,
    contact_phone: f.phone || null,
    latitude: point?.lat ?? null,
    longitude: point?.lng ?? null,
    fee: f.fee === "" ? null : Number(f.fee),
    note: f.note || null,
  }), { invalidate: [["admin/deliveries"]], onSuccess: () => { onAssigned?.(); onClose(); } });
  const e = fieldErrors(assign.error);

  return (
    <Modal open={open} onClose={onClose} size="lg" title={t("Give to a delivery person")} description={t("They get it in their bell, and the customer gets a link to follow the delivery and chat.")}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<Bike className="size-4" />} loading={assign.isPending} disabled={!courierId} onClick={() => assign.mutate(undefined)}>{t("Assign")}</Button></>}>
      <div className="space-y-4">
        <fieldset>
          <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Delivery person")}</legend>
          {couriers.data?.data.length === 0 && <p className="rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-800">{t("Nobody has the Delivery person role yet. Give a staff user that role on the Staff users page.")}</p>}
          <div className="grid gap-2 sm:grid-cols-2">
            {couriers.data?.data.map((c) => (
              <button key={c.id} type="button" onClick={() => setCourierId(c.id)} aria-pressed={courierId === c.id}
                className={cn("flex items-center justify-between rounded-md px-3 py-2 text-left ring-1", courierId === c.id ? "bg-brand-50 ring-brand-500" : "ring-ink-200 hover:bg-ink-50")}>
                <span><span className="block text-sm font-medium text-ink-900">{c.name}</span><span className="text-xs text-ink-500">{c.phone ?? ""}</span></span>
                <span className={cn("text-xs", c.open_deliveries ? "text-saffron-700" : "text-brand-700")}>{c.open_deliveries ? t("{count} on hand", { count: c.open_deliveries }) : t("Free")}</span>
              </button>
            ))}
          </div>
          {e.courier_id && <p className="mt-1 text-xs text-ember-600">{e.courier_id}</p>}
        </fieldset>
        <div>
          <div className="mb-1.5 flex flex-wrap items-end justify-between gap-2">
            <p className="text-[13px] font-medium text-ink-700">{t("Customer's place")}</p>
            <UseMyLocation label={t("We are at the customer's place")} onLocated={setPoint} />
          </div>
          <PickerMap value={point} onChange={setPoint} center={shop} others={shop ? [{ ...shop, kind: "shop" }] : []} height={240} />
          {quote && <p className="mt-1 text-sm text-ink-600">{quote.distance_km !== null ? t("About {km} km · fee by distance {fee}", { km: quote.distance_km, fee: money(quote.fee) }) : t("Fee {fee}", { fee: money(quote.fee) })}</p>}
          {quoteError && <p className="mt-1 text-sm text-ember-600">{quoteError}</p>}
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t("Address")} error={e.address}>{(p) => <Input {...p} value={f.address} onChange={(ev) => setF({ ...f, address: ev.target.value })} />}</Field>
          <Field label={t("Customer's phone")} error={e.contact_phone}>{(p) => <Input {...p} type="tel" value={f.phone} onChange={(ev) => setF({ ...f, phone: ev.target.value })} />}</Field>
          <Field label={t("Delivery fee")} hint={f.fee === "" ? (quote ? t("Empty = {fee} by distance", { fee: money(quote.fee) }) : t("Empty = worked out from the distance")) : undefined} error={e.fee}>
            {(p) => <Input {...p} type="number" min={0} step="any" value={f.fee} onChange={(ev) => setF({ ...f, fee: ev.target.value })} />}
          </Field>
          <Field label={t("Note for the delivery person")} error={e.note}>{(p) => <Textarea {...p} rows={1} value={f.note} onChange={(ev) => setF({ ...f, note: ev.target.value })} />}</Field>
        </div>
      </div>
    </Modal>
  );
}
