"use client";

import { MapPin } from "lucide-react";
import { money, toNumber } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { PickerMap, toLatLng, UseMyLocation } from "@/components/map";

/**
 * Settings → Delivery: where the shop is (deliveries are priced by distance
 * from here unless a branch has its own location) and a worked example of the fee.
 */
export function DeliveryEditor({ values, set, errors, disabled, currency }: {
  values: Record<string, unknown>;
  set: (key: string, value: unknown) => void;
  errors: Record<string, string>;
  disabled: boolean;
  currency?: string;
}) {
  const t = useT();
  const point = toLatLng(values.business_latitude, values.business_longitude);
  const pick = (p: { lat: number; lng: number }) => {
    set("business_latitude", String(p.lat));
    set("business_longitude", String(p.lng));
  };

  // Same formula as the server (DeliveryFees): base + per km x road distance, at least the minimum, rounded up.
  const example = (km: number) => {
    const perKm = toNumber(values.delivery_fee_per_km);
    if (!perKm) return null;
    const road = km * (toNumber(values.delivery_road_factor) || 1);
    const step = toNumber(values.delivery_round_to) || 1;
    const fee = Math.max(toNumber(values.delivery_base_fee) + perKm * road, toNumber(values.delivery_min_fee));
    return Math.ceil(fee / step) * step;
  };

  return (
    <div className="mb-7 space-y-3 border-b border-ink-100 pb-7">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h3 className="flex items-center gap-1.5 text-[15px] font-semibold text-ink-900"><MapPin className="size-4" /> {t("Shop location")}</h3>
          <p className="text-sm text-ink-500">{t("Tap the map where the shop is, or stand in the shop and use your location. Branches can have their own location (Branches page).")}</p>
        </div>
        {!disabled && <UseMyLocation onLocated={pick} />}
      </div>
      <PickerMap value={point} onChange={pick} disabled={disabled} height={300} />
      <p className="num text-xs text-ink-500">
        {point ? t("Latitude {lat}, longitude {lng}", { lat: point.lat, lng: point.lng }) : t("No location chosen yet: the flat delivery fee of the online shop is used.")}
      </p>
      {(errors.business_latitude || errors.business_longitude) && <p className="text-xs text-ember-600">{errors.business_latitude ?? errors.business_longitude}</p>}
      {example(5) !== null && (
        <p className="rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-800">
          {t("Example: 2 km straight-line costs {a}, 5 km costs {b}, 10 km costs {c}.", {
            a: money(example(2), currency), b: money(example(5), currency), c: money(example(10), currency),
          })}
        </p>
      )}
    </div>
  );
}

export const DELIVERY_SETTING_KEYS = new Set(["business_latitude", "business_longitude"]);
