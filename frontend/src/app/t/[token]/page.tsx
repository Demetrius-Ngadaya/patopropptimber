"use client";

import { use, useEffect, useState } from "react";
import { Bike, CheckCircle2, PackageCheck, Phone, Store } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { cn, dateTime, formatDate, money } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { BrandLogo } from "@/components/brand/BrandLogo";
import { LanguageSwitch } from "@/components/layout/PreferenceSwitches";
import { Badge, Button, ConfirmDialog, ErrorState, Spinner } from "@/components/ui";
import { TrackingMap, type MapPoint } from "@/components/map";
import { DeliveryChat } from "@/features/delivery/DeliveryChat";
import { DELIVERY_STATUS_LABELS, DELIVERY_STATUS_TONES, type DeliveryStatus } from "@/features/delivery/types";

type Tracking = {
  number: string;
  status: DeliveryStatus;
  customer_name: string;
  address: string | null;
  destination: [number, number] | null;
  origin: [number, number] | null;
  branch: { name: string; phone: string | null } | null;
  courier: { name: string; phone: string | null } | null;
  courier_position: [number, number] | null;
  courier_located_at: string | null;
  distance_km: string | null;
  fee: number;
  discount: number;
  final_fee: number;
  reference: string | null;
  total: number;
  assigned_at: string | null;
  picked_up_at: string | null;
  delivered_at: string | null;
  received_at: string | null;
  can_confirm: boolean;
  can_chat: boolean;
};

/** The customer follows their delivery: where the rider is, the steps, a chat, and "I received it". */
export default function TrackingPage({ params }: { params: Promise<{ token: string }> }) {
  const t = useT();
  const { token } = use(params);
  const { settings } = usePublicSettings();
  const path = `public/deliveries/${token}`;
  const q = useApi<ApiResponse<Tracking>>(path, undefined, { keepPrevious: true });
  const [confirming, setConfirming] = useState(false);
  const receive = useAction(() => api.post(`${path}/received`), { invalidate: [[path]], onSuccess: () => setConfirming(false) });

  // Live: the rider's position is refreshed every 10 seconds while the page is open.
  const { refetch } = q;
  const status = q.data?.data.status;
  useEffect(() => {
    if (status && !["on_the_way", "assigned", "delivered"].includes(status)) return;
    const id = setInterval(() => document.visibilityState === "visible" && refetch(), status === "on_the_way" ? 10000 : 30000);
    return () => clearInterval(id);
  }, [refetch, status]);

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <div className="mx-auto max-w-lg p-6"><ErrorState message={q.error?.status === 404 ? t("This tracking link is not valid.") : q.error?.message ?? t("Not found.")} /></div>;
  const d = q.data.data;

  const points: MapPoint[] = [
    ...(d.origin ? [{ lat: d.origin[0], lng: d.origin[1], kind: "shop" as const, label: d.branch?.name ?? settings?.business_name }] : []),
    ...(d.destination ? [{ lat: d.destination[0], lng: d.destination[1], kind: "customer" as const, label: t("You") }] : []),
    ...(d.courier_position ? [{ lat: d.courier_position[0], lng: d.courier_position[1], kind: "courier" as const, label: d.courier?.name ?? "" }] : []),
  ];
  const steps = [
    { icon: Store, label: t("Being prepared"), at: d.assigned_at, done: true },
    { icon: Bike, label: t("On the way"), at: d.picked_up_at, done: Boolean(d.picked_up_at) },
    { icon: PackageCheck, label: t("Delivered"), at: d.delivered_at, done: Boolean(d.delivered_at) },
    { icon: CheckCircle2, label: t("Received"), at: d.received_at, done: Boolean(d.received_at) },
  ];

  return (
    <div className="min-h-dvh bg-ink-100">
      <header className="flex items-center justify-between gap-3 bg-surface px-4 py-3 shadow-sm">
        <div className="flex min-w-0 items-center gap-2.5">
          <BrandLogo size="sm" />
          <span className="truncate font-semibold text-ink-900">{settings?.business_name}</span>
        </div>
        <LanguageSwitch compact />
      </header>

      <main className="mx-auto max-w-3xl space-y-4 px-3 py-4">
        <section className="rounded-xl bg-surface p-4 shadow-sm ring-1 ring-ink-200">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="text-sm text-ink-500">{t("Delivery {number}", { number: d.number })}{d.reference ? ` · ${d.reference}` : ""}</p>
              <h1 className="text-xl font-semibold text-ink-950">
                {d.status === "on_the_way" ? t("{name} is on the way", { name: d.courier?.name ?? t("Your delivery") }) : t(DELIVERY_STATUS_LABELS[d.status])}
              </h1>
              {d.status === "on_the_way" && d.courier_located_at && <p className="text-xs text-ink-500">{t("Position updated at {time}", { time: formatDate(d.courier_located_at, { hour: "2-digit", minute: "2-digit" }) })}</p>}
            </div>
            <Badge tone={DELIVERY_STATUS_TONES[d.status]}>{t(DELIVERY_STATUS_LABELS[d.status])}</Badge>
          </div>
          <ol className="mt-4 grid grid-cols-4 gap-1">
            {steps.map((s) => (
              <li key={s.label} className="flex flex-col items-center text-center">
                <span className={cn("grid size-9 place-items-center rounded-full", s.done ? "bg-brand-600 text-white" : "bg-ink-100 text-ink-400")}><s.icon className="size-4" /></span>
                <span className={cn("mt-1 text-xs", s.done ? "font-medium text-ink-900" : "text-ink-500")}>{s.label}</span>
                {s.at && s.done && <span className="text-[10.5px] text-ink-500">{formatDate(s.at, { hour: "2-digit", minute: "2-digit" })}</span>}
              </li>
            ))}
          </ol>
          {d.can_confirm && (
            <Button size="lg" className="mt-4 w-full" icon={<CheckCircle2 className="size-5" />} onClick={() => setConfirming(true)}>{t("I have received my goods")}</Button>
          )}
          {d.status === "received" && <p className="mt-4 rounded-md bg-brand-50 px-3 py-2 text-center text-sm text-brand-800">{t("Thank you! You confirmed on {date}.", { date: dateTime(d.received_at) })}</p>}
        </section>

        {points.length > 0 && (
          <section className="overflow-hidden rounded-xl bg-surface shadow-sm ring-1 ring-ink-200">
            <TrackingMap points={points} height={320} />
            {d.status === "on_the_way" && !d.courier_position && <p className="px-4 py-2 text-xs text-ink-500">{t("The rider's position will appear when their phone shares it.")}</p>}
          </section>
        )}

        <section className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-xl bg-surface p-4 shadow-sm ring-1 ring-ink-200">
            <h2 className="mb-2 font-semibold text-ink-900">{t("Details")}</h2>
            <dl className="space-y-1 text-sm">
              {d.address && <div><dt className="text-ink-500">{t("To")}</dt><dd>{d.address}</dd></div>}
              {d.courier && <div className="flex items-center justify-between gap-2"><span><dt className="text-ink-500">{t("Delivery person")}</dt><dd>{d.courier.name}</dd></span>{d.courier.phone && <a href={`tel:${d.courier.phone}`} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-3 py-1 text-sm font-medium text-brand-700"><Phone className="size-4" /> {t("Call")}</a>}</div>}
              <div className="flex justify-between border-t border-ink-100 pt-2"><dt className="text-ink-500">{t("Delivery fee")}</dt><dd className="num">{money(d.fee)}</dd></div>
              {d.discount > 0 && <div className="flex justify-between text-brand-700"><dt>{t("Discount")}</dt><dd className="num">−{money(d.discount)}</dd></div>}
              {d.total > 0 && <div className="flex justify-between font-semibold"><dt>{t("Order total")}</dt><dd className="num">{settings?.currency} {money(d.total)}</dd></div>}
            </dl>
            {d.branch?.phone && <a href={`tel:${d.branch.phone}`} className="mt-3 inline-flex items-center gap-1.5 text-sm text-ink-600 hover:underline"><Phone className="size-4" /> {t("Call the shop")}</a>}
          </div>
          <div className="rounded-xl bg-surface p-4 shadow-sm ring-1 ring-ink-200">
            <h2 className="mb-2 font-semibold text-ink-900">{t("Chat with the delivery person")}</h2>
            <DeliveryChat path={`${path}/messages`} me="customer" disabled={!d.can_chat} className="h-80" />
          </div>
        </section>
      </main>

      <ConfirmDialog open={confirming} onClose={() => setConfirming(false)} tone="primary" title={t("Did you receive everything?")} message={t("Confirm only once the goods are in your hands.")} confirmLabel={t("Yes, I received them")} loading={receive.isPending} onConfirm={() => receive.mutate(undefined)} />
    </div>
  );
}
