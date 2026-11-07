"use client";

import Link from "next/link";
import { use, useEffect, useRef, useState } from "react";
import { ArrowLeft, Ban, BadgePercent, CheckCircle2, Copy, MapPinOff, Navigation, Phone, Radio, XCircle } from "lucide-react";
import { toast } from "sonner";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, dateTime, money, qty, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ErrorState, Field, Input, Modal, PageHeader, Panel, Spinner, Textarea } from "@/components/ui";
import { DirectionsLink, toLatLng, TrackingMap, type MapPoint } from "@/components/map";
import { DeliveryChat } from "@/features/delivery/DeliveryChat";
import { DELIVERY_STATUS_LABELS, DELIVERY_STATUS_TONES, type DeliveryDetail } from "@/features/delivery/types";

type Dialog = null | "discount" | "fail" | "cancel" | "delivered";

export default function DeliveryPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const { user, can } = useAuth();
  const { settings } = usePublicSettings();
  const path = `admin/deliveries/${id}`;
  const q = useApi<ApiResponse<DeliveryDetail>>(path, undefined, { keepPrevious: true });
  const [dialog, setDialog] = useState<Dialog>(null);
  const close = () => setDialog(null);
  const act = useAction((v: { action: string; body?: unknown }) => api.post(`${path}/${v.action}`, v.body ?? {}), { invalidate: [[path], ["admin/deliveries"]], onSuccess: close });

  // Follow changes made by others (the customer confirming, the shop cancelling).
  const { refetch } = q;
  useEffect(() => {
    const timer = setInterval(() => document.visibilityState === "visible" && refetch(), 15000);
    return () => clearInterval(timer);
  }, [refetch]);

  const d = q.data?.data;
  const isCourier = Boolean(d && user && d.assigned_to === user.id);
  const sharing = useLocationSharing(path, Boolean(d && isCourier && d.status === "on_the_way"));

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !d) return <ErrorState message={q.error?.message ?? t("Not found.")} onRetry={() => q.refetch()} />;

  const destination = toLatLng(d.latitude, d.longitude);
  const origin = d.origin ? { lat: d.origin[0], lng: d.origin[1] } : null;
  const courierAt = sharing.position ?? toLatLng(d.courier_latitude, d.courier_longitude);
  const points: MapPoint[] = [
    ...(origin ? [{ ...origin, kind: "shop" as const, label: d.branch?.name ?? settings?.business_name }] : []),
    ...(destination ? [{ ...destination, kind: "customer" as const, label: d.customer_name }] : []),
    ...(courierAt && d.status === "on_the_way" ? [{ ...courierAt, kind: "courier" as const, label: d.courier?.name ?? "" }] : []),
  ];
  const items = d.order?.items ?? d.sale?.items ?? [];
  const goodsTotal = toNumber(d.order?.total ?? d.sale?.total);
  // An online order already includes its delivery fee; a till sale's fee is collected on delivery.
  const toCollect = d.order ? goodsTotal : d.final_fee;
  const open = d.status === "assigned" || d.status === "on_the_way";
  const canAct = d.can_act;

  return (
    <Guard perm={["deliveries.view", "deliveries.assign", "deliveries.own"]}>
      <PageHeader
        back={<Link href="/admin/deliveries" className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Deliveries")}</Link>}
        title={`${t("Delivery")} ${d.number}`}
        description={<span className="flex flex-wrap items-center gap-2"><Badge tone={DELIVERY_STATUS_TONES[d.status]}>{t(DELIVERY_STATUS_LABELS[d.status])}</Badge><span>{d.customer_name}</span>{d.courier && <span>· {d.courier.name}</span>}</span>}
        actions={
          <>
            {d.status === "assigned" && isCourier && <Button size="lg" icon={<Navigation className="size-5" />} loading={act.isPending} onClick={() => act.mutate({ action: "start" })}>{t("Start the trip")}</Button>}
            {d.status === "on_the_way" && canAct && <Button size="lg" icon={<CheckCircle2 className="size-5" />} onClick={() => setDialog("delivered")}>{t("Delivered")}</Button>}
          </>
        }
      />

      {d.status === "on_the_way" && isCourier && (
        <div className={cn("mb-4 flex items-center gap-2 rounded-md px-3 py-2 text-sm", sharing.error ? "bg-ember-50 text-ember-700" : "bg-brand-50 text-brand-800")}>
          {sharing.error ? <MapPinOff className="size-4" /> : <Radio className="size-4 animate-pulse" />}
          {sharing.error ?? (sharing.lastSent ? t("Sharing your location with the customer (last sent {time}).", { time: dateTime(sharing.lastSent) }) : t("Finding your location…"))}
        </div>
      )}
      {d.status === "delivered" && <p className="mb-4 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-800">{t("Waiting for the customer to confirm they received the goods.")}</p>}
      {d.status === "failed" && d.failed_reason && <p className="mb-4 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{t("Could not deliver: {reason}", { reason: d.failed_reason })}</p>}

      <div className="grid gap-5 xl:grid-cols-[1fr_380px]">
        <div className="space-y-5">
          <Panel padded={false}>
            {points.length ? <TrackingMap points={points} trail={d.trail.map(([lat, lng]) => ({ lat, lng }))} height={380} /> : <p className="p-6 text-center text-sm text-ink-500">{t("No map position for this customer. Use the address and call them.")}</p>}
            <div className="flex flex-wrap items-center gap-4 border-t border-ink-100 px-4 py-3">
              {destination && <DirectionsLink to={destination} />}
              {d.contact_phone && <a href={`tel:${d.contact_phone}`} className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline"><Phone className="size-4" /> {d.contact_phone}</a>}
              {d.distance_km && <span className="text-sm text-ink-500">{t("About {km} km from the shop", { km: Number(d.distance_km) })}</span>}
            </div>
          </Panel>

          <Panel title={t("Goods")}>
            <p className="mb-2 text-sm text-ink-600">{d.address ?? t("No address given")}</p>
            {d.note && <p className="mb-3 rounded-md bg-ink-50 px-3 py-2 text-sm text-ink-700">{d.note}</p>}
            <ul className="divide-y divide-ink-100 text-sm">
              {items.map((i) => <li key={i.id} className="flex justify-between gap-3 py-1.5"><span>{i.product_name} <span className="text-ink-500">× {qty(i.quantity)}</span></span><span className="num">{money(i.line_total)}</span></li>)}
            </ul>
            <dl className="mt-3 space-y-1 border-t border-ink-100 pt-3 text-sm">
              <div className="flex justify-between"><dt className="text-ink-600">{t("Delivery fee")}</dt><dd className="num">{money(d.fee)}</dd></div>
              {toNumber(d.discount) > 0 && <div className="flex justify-between text-brand-700"><dt>{t("Delivery discount")}{d.discount_reason ? ` (${d.discount_reason})` : ""}</dt><dd className="num">−{money(d.discount)}</dd></div>}
              <div className="flex justify-between text-base font-semibold"><dt>{d.order ? t("Customer pays on delivery") : t("Delivery fee to collect")}</dt><dd className="num">{money(toCollect)}</dd></div>
            </dl>
            {open && canAct && (
              <div className="mt-4 flex flex-wrap gap-2">
                <Button variant="secondary" icon={<BadgePercent className="size-4" />} onClick={() => setDialog("discount")}>{t("Give a discount")}</Button>
                <Button variant="ghost" className="text-ember-600" icon={<XCircle className="size-4" />} onClick={() => setDialog("fail")}>{t("Could not deliver")}</Button>
                {can("deliveries.assign") && <Button variant="ghost" className="text-ember-600" icon={<Ban className="size-4" />} onClick={() => setDialog("cancel")}>{t("Cancel delivery")}</Button>}
              </div>
            )}
          </Panel>
        </div>

        <div className="space-y-5">
          <Panel title={t("Chat with {name}", { name: d.customer_name })}>
            <DeliveryChat path={`${path}/messages`} me="staff" disabled={["cancelled", "received", "failed"].includes(d.status)} className="h-[420px]" />
          </Panel>
          <Panel title={t("Steps")}>
            <ol className="space-y-2 text-sm">
              <Step done label={t("Given to {name}", { name: d.courier?.name ?? "" })} at={d.assigned_at} />
              <Step done={Boolean(d.picked_up_at)} label={t("On the way")} at={d.picked_up_at} />
              <Step done={Boolean(d.delivered_at)} label={t("Delivered")} at={d.delivered_at} />
              <Step done={Boolean(d.received_at)} label={t("Customer confirmed")} at={d.received_at} />
            </ol>
            <button type="button" className="mt-4 inline-flex items-center gap-1.5 text-xs text-ink-500 hover:text-ink-800" onClick={() => navigator.clipboard?.writeText(d.tracking_url).then(() => toast.success(t("Link copied.")))}>
              <Copy className="size-3.5" /> {t("Copy the customer's tracking link")}
            </button>
          </Panel>
        </div>
      </div>

      <DiscountDialog open={dialog === "discount"} onClose={close} fee={toNumber(d.fee)} current={toNumber(d.discount)} loading={act.isPending} error={act.error} onSave={(body) => act.mutate({ action: "discount", body })} />
      <TextDialog open={dialog === "delivered"} onClose={close} title={t("Goods handed over?")} label={t("Note (optional)")} confirm={t("Yes, delivered")} loading={act.isPending} onConfirm={(note) => act.mutate({ action: "delivered", body: { note: note || null } })}
        description={t("The customer is asked to confirm they received them.")} />
      <TextDialog open={dialog === "fail"} onClose={close} title={t("Could not deliver")} label={t("What happened?")} required danger confirm={t("Record")} loading={act.isPending} onConfirm={(reason) => act.mutate({ action: "fail", body: { reason } })} />
      <TextDialog open={dialog === "cancel"} onClose={close} title={t("Cancel delivery {number}", { number: d.number })} label={t("Reason")} required danger confirm={t("Cancel delivery")} loading={act.isPending} onConfirm={(reason) => act.mutate({ action: "cancel", body: { reason } })} />
    </Guard>
  );
}

function Step({ done, label, at }: { done: boolean; label: string; at: string | null }) {
  return (
    <li className="flex items-start gap-2">
      <span className={cn("mt-0.5 grid size-5 shrink-0 place-items-center rounded-full", done ? "bg-brand-600 text-white" : "bg-ink-100 text-ink-400")}><CheckCircle2 className="size-3.5" /></span>
      <span><span className={cn("block", done ? "text-ink-900" : "text-ink-500")}>{label}</span>{at && <span className="text-xs text-ink-500">{dateTime(at)}</span>}</span>
    </li>
  );
}

/**
 * While the delivery person is on the way, send their position: right away,
 * then whenever they have moved about 40 m or 20 seconds have passed.
 * Also asks the phone to keep the screen on.
 */
function useLocationSharing(path: string, active: boolean) {
  const t = useT();
  const [position, setPosition] = useState<{ lat: number; lng: number } | null>(null);
  const [lastSent, setLastSent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const last = useRef<{ at: number; lat: number; lng: number } | null>(null);

  useEffect(() => {
    if (!active) return;
    if (!navigator.geolocation) { setError(t("This device cannot share its location.")); return; }
    let lock: { release: () => Promise<void> } | null = null;
    (navigator as Navigator & { wakeLock?: { request: (type: "screen") => Promise<{ release: () => Promise<void> }> } }).wakeLock?.request("screen").then((l) => { lock = l; }).catch(() => undefined);

    const watch = navigator.geolocation.watchPosition((p) => {
      const here = { lat: p.coords.latitude, lng: p.coords.longitude };
      setPosition(here);
      setError(null);
      const prev = last.current;
      const moved = prev ? metres(prev, here) : Infinity;
      if (!prev || moved > 40 || Date.now() - prev.at > 20000) {
        last.current = { at: Date.now(), ...here };
        api.post(`${path}/location`, { latitude: here.lat, longitude: here.lng, accuracy: Math.round(p.coords.accuracy) })
          .then(() => setLastSent(new Date().toISOString()))
          .catch(() => { last.current = prev; });
      }
    }, (e) => setError(e.code === e.PERMISSION_DENIED ? t("Location is blocked. Allow location for this site so the customer can follow you.") : t("Waiting for a GPS signal…")), { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });

    return () => {
      navigator.geolocation.clearWatch(watch);
      lock?.release().catch(() => undefined);
    };
  }, [active, path, t]);

  return { position, lastSent, error };
}

function metres(a: { lat: number; lng: number }, b: { lat: number; lng: number }) {
  const r = 6371000, rad = Math.PI / 180;
  const dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
  return 2 * r * Math.asin(Math.sqrt(h));
}

function DiscountDialog({ open, onClose, fee, current, onSave, loading, error }: { open: boolean; onClose: () => void; fee: number; current: number; onSave: (b: unknown) => void; loading: boolean; error: unknown }) {
  const t = useT();
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [wasOpen, setWasOpen] = useState(false);
  if (open !== wasOpen) { setWasOpen(open); if (open) { setAmount(current ? String(current) : ""); setReason(""); } }
  const e = fieldErrors(error);
  return (
    <Modal open={open} onClose={onClose} size="sm" title={t("Discount on the delivery fee")} description={t("Delivery fee {fee}. The customer pays the rest.", { fee: money(fee) })}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button loading={loading} onClick={() => onSave({ amount: Number(amount) || 0, reason: reason || null })}>{t("Save")}</Button></>}>
      <div className="space-y-3">
        <Field label={t("Discount")} error={e.amount}>{(p) => <Input {...p} type="number" min={0} max={fee} step="any" value={amount} onChange={(ev) => setAmount(ev.target.value)} />}</Field>
        <div className="flex flex-wrap gap-1.5">
          {[0.25, 0.5, 1].map((part) => <button key={part} type="button" onClick={() => setAmount(String(Math.round(fee * part)))} className="rounded-full bg-ink-100 px-2.5 py-0.5 text-xs hover:bg-brand-50">{part === 1 ? t("Free delivery") : `${part * 100}%`}</button>)}
        </div>
        <Field label={t("Reason (optional)")} error={e.reason}>{(p) => <Input {...p} value={reason} onChange={(ev) => setReason(ev.target.value)} placeholder={t("e.g. loyal customer, short distance")} />}</Field>
      </div>
    </Modal>
  );
}

function TextDialog({ open, onClose, title, description, label, required, danger, confirm, loading, onConfirm }: {
  open: boolean; onClose: () => void; title: string; description?: string; label: string; required?: boolean; danger?: boolean; confirm: string; loading: boolean; onConfirm: (text: string) => void;
}) {
  const t = useT();
  const [text, setText] = useState("");
  const [wasOpen, setWasOpen] = useState(false);
  if (open !== wasOpen) { setWasOpen(open); if (open) setText(""); }
  return (
    <Modal open={open} onClose={onClose} size="sm" title={title} description={description}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Back")}</Button><Button variant={danger ? "danger" : "primary"} loading={loading} disabled={required && text.trim().length < 3} onClick={() => onConfirm(text.trim())}>{confirm}</Button></>}>
      <Field label={label} required={required}>{(p) => <Textarea {...p} rows={3} value={text} onChange={(e) => setText(e.target.value)} />}</Field>
    </Modal>
  );
}
