"use client";

import Link from "next/link";
import { useState } from "react";
import { Bike } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";
import { Badge, Button } from "@/components/ui";
import type { LatLng } from "@/components/map";
import { AssignDeliveryDialog } from "./AssignDeliveryDialog";
import { DELIVERY_STATUS_LABELS, DELIVERY_STATUS_TONES, type Delivery } from "./types";

/** On an order or a sale: who is delivering it, or a button to give it to a delivery person. */
export function DeliveryBox({ delivery, source, address, phone, pin, fee, canDeliver, onChanged }: {
  delivery: Pick<Delivery, "id" | "number" | "status" | "courier"> | null | undefined;
  source: { order_id: number } | { sale_id: number };
  address?: string | null;
  phone?: string | null;
  pin?: LatLng | null;
  fee?: number | null;
  /** The order/sale is in a state where it can still be delivered. */
  canDeliver: boolean;
  onChanged: () => void;
}) {
  const t = useT();
  const { can } = useAuth();
  const [open, setOpen] = useState(false);
  const active = delivery && !["cancelled", "failed"].includes(delivery.status);

  if (active) {
    return (
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md bg-sky-50 px-4 py-2.5 text-sm text-sky-900 ring-1 ring-sky-100">
        <span className="flex flex-wrap items-center gap-2">
          <Bike className="size-4" />
          {t("Delivery {number} with {name}", { number: delivery.number, name: delivery.courier?.name ?? "—" })}
          <Badge tone={DELIVERY_STATUS_TONES[delivery.status]}>{t(DELIVERY_STATUS_LABELS[delivery.status])}</Badge>
        </span>
        <Link href={`/admin/deliveries/${delivery.id}`} className="font-semibold underline underline-offset-2">{t("Open delivery")}</Link>
      </div>
    );
  }
  if (!canDeliver || !can("deliveries.assign")) return null;

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md bg-ink-50 px-4 py-2.5 text-sm text-ink-700 ring-1 ring-ink-100">
        <span>{delivery ? t("The last delivery was {status}. You can give it to someone again.", { status: t(DELIVERY_STATUS_LABELS[delivery.status]).toLowerCase() }) : t("Does the customer want this delivered?")}</span>
        <Button size="sm" icon={<Bike className="size-4" />} onClick={() => setOpen(true)}>{t("Give to a delivery person")}</Button>
      </div>
      <AssignDeliveryDialog open={open} onClose={() => setOpen(false)} source={source} address={address} phone={phone} pin={pin} fee={fee} onAssigned={onChanged} />
    </>
  );
}
