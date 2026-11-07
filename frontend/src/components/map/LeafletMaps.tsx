"use client";

import "leaflet/dist/leaflet.css";
import L from "leaflet";
import { useEffect, useMemo } from "react";
import { MapContainer, Marker, Polyline, TileLayer, Tooltip, useMap, useMapEvents } from "react-leaflet";

export type LatLng = { lat: number; lng: number };
export type MapPoint = LatLng & { kind: "shop" | "customer" | "courier"; label?: string };

/** Dar es Salaam: where the map opens when nothing better is known. */
export const FALLBACK_CENTER: LatLng = { lat: -6.7924, lng: 39.2083 };

const TILES = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

// Drawn markers (no image files to bundle), one colour per kind.
const colours = { shop: "#13213b", customer: "#c2410c", courier: "#157a55" };
const glyphs = { shop: "🏪", customer: "📍", courier: "🛵" };
function icon(kind: MapPoint["kind"]) {
  return L.divIcon({
    className: "",
    html: `<div style="display:grid;place-items:center;width:34px;height:34px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:${colours[kind]};box-shadow:0 2px 6px rgba(0,0,0,.35);border:2px solid #fff"><span style="transform:rotate(45deg);font-size:16px;line-height:1">${glyphs[kind]}</span></div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 34],
  });
}

function ClickToPick({ onPick }: { onPick: (p: LatLng) => void }) {
  useMapEvents({ click: (e) => onPick({ lat: round(e.latlng.lat), lng: round(e.latlng.lng) }) });
  return null;
}

function Recenter({ to, zoom }: { to: LatLng | null; zoom?: number }) {
  const map = useMap();
  useEffect(() => {
    if (to) map.setView([to.lat, to.lng], zoom ?? Math.max(map.getZoom(), 15));
  }, [to?.lat, to?.lng]); // eslint-disable-line react-hooks/exhaustive-deps -- follow the point only
  return null;
}

function FitAll({ points }: { points: LatLng[] }) {
  const map = useMap();
  const key = points.map((p) => `${p.lat.toFixed(4)},${p.lng.toFixed(4)}`).join("|");
  useEffect(() => {
    if (points.length === 1) map.setView([points[0].lat, points[0].lng], 15);
    if (points.length > 1) map.fitBounds(L.latLngBounds(points.map((p) => [p.lat, p.lng])), { padding: [40, 40], maxZoom: 16 });
  }, [key]); // eslint-disable-line react-hooks/exhaustive-deps -- refit only when a point really moves
  return null;
}

const round = (n: number) => Math.round(n * 1e6) / 1e6;

/** Click (or drag the pin) to choose a place. */
export function PickerMap({ value, onChange, center, height = 320, others = [], disabled }: {
  value: LatLng | null;
  onChange: (p: LatLng) => void;
  center?: LatLng | null;
  height?: number;
  /** Fixed reference points, e.g. the shop. */
  others?: MapPoint[];
  disabled?: boolean;
}) {
  const start = value ?? center ?? FALLBACK_CENTER;
  const handlers = useMemo(() => ({
    dragend: (e: L.DragEndEvent) => {
      const p = (e.target as L.Marker).getLatLng();
      onChange({ lat: round(p.lat), lng: round(p.lng) });
    },
  }), [onChange]);

  return (
    <MapContainer center={[start.lat, start.lng]} zoom={value ? 16 : 13} style={{ height, width: "100%", borderRadius: 8, zIndex: 0 }} scrollWheelZoom>
      <TileLayer url={TILES} attribution={ATTRIBUTION} />
      {!disabled && <ClickToPick onPick={onChange} />}
      <Recenter to={value} />
      {others.map((o, i) => (
        <Marker key={`o${i}`} position={[o.lat, o.lng]} icon={icon(o.kind)}>{o.label && <Tooltip>{o.label}</Tooltip>}</Marker>
      ))}
      {value && <Marker position={[value.lat, value.lng]} icon={icon("customer")} draggable={!disabled} eventHandlers={handlers} />}
    </MapContainer>
  );
}

/** Shop, customer and courier with the courier's recent path. Zooms to show them all. */
export function TrackingMap({ points, trail = [], height = 340 }: { points: MapPoint[]; trail?: LatLng[]; height?: number }) {
  const start = points[0] ?? FALLBACK_CENTER;
  return (
    <MapContainer center={[start.lat, start.lng]} zoom={14} style={{ height, width: "100%", borderRadius: 8, zIndex: 0 }} scrollWheelZoom>
      <TileLayer url={TILES} attribution={ATTRIBUTION} />
      <FitAll points={points} />
      {trail.length > 1 && <Polyline positions={trail.map((p) => [p.lat, p.lng])} pathOptions={{ color: colours.courier, weight: 4, opacity: 0.6, dashArray: "6 8" }} />}
      {points.map((p, i) => (
        <Marker key={`${p.kind}${i}`} position={[p.lat, p.lng]} icon={icon(p.kind)}>{p.label && <Tooltip permanent={p.kind === "courier"} direction="top" offset={[0, -30]}>{p.label}</Tooltip>}</Marker>
      ))}
    </MapContainer>
  );
}
