"use client";

import dynamic from "next/dynamic";
import { useState } from "react";
import { Crosshair, ExternalLink } from "lucide-react";
import { Button } from "@/components/ui";
import { useT } from "@/lib/i18n";
import type { LatLng } from "./LeafletMaps";

export type { LatLng, MapPoint } from "./LeafletMaps";

// Leaflet touches `window`, so the maps are only drawn in the browser.
const loading = () => <div className="grid h-full min-h-40 place-items-center rounded-lg bg-ink-50 text-sm text-ink-500">…</div>;
export const PickerMap = dynamic(() => import("./LeafletMaps").then((m) => m.PickerMap), { ssr: false, loading });
export const TrackingMap = dynamic(() => import("./LeafletMaps").then((m) => m.TrackingMap), { ssr: false, loading });

/** Ask the phone/browser where we are (one reading). */
export function currentPosition(): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error("Location is not available on this device."));
    navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
  });
}

/** "Use my location" button that fills a point from the device. */
export function UseMyLocation({ onLocated, label }: { onLocated: (p: LatLng) => void; label?: string }) {
  const t = useT();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <div>
      <Button type="button" size="sm" variant="secondary" loading={busy} icon={<Crosshair className="size-4" />}
        onClick={async () => {
          setBusy(true);
          setError(null);
          try {
            const p = await currentPosition();
            onLocated({ lat: Math.round(p.coords.latitude * 1e6) / 1e6, lng: Math.round(p.coords.longitude * 1e6) / 1e6 });
          } catch {
            setError(t("We could not get your location. Allow location access, or tap the map instead."));
          } finally {
            setBusy(false);
          }
        }}>
        {label ?? t("Use my location")}
      </Button>
      {error && <p className="mt-1 text-xs text-ember-600">{error}</p>}
    </div>
  );
}

/** Opens turn-by-turn directions in Google Maps (app on phones). */
export function DirectionsLink({ to, className }: { to: LatLng; className?: string }) {
  const t = useT();
  return (
    <a href={`https://www.google.com/maps/dir/?api=1&destination=${to.lat},${to.lng}&travelmode=driving`} target="_blank" rel="noreferrer"
      className={className ?? "inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline"}>
      <ExternalLink className="size-4" /> {t("Directions")}
    </a>
  );
}

export const toLatLng = (lat: unknown, lng: unknown): LatLng | null => {
  const a = parseFloat(String(lat ?? ""));
  const b = parseFloat(String(lng ?? ""));
  return Number.isFinite(a) && Number.isFinite(b) ? { lat: a, lng: b } : null;
};
