"use client";

import { useRef, useState } from "react";
import { Expand, Move, RotateCcw, RotateCw, Shrink } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import type { ShopProduct } from "@/features/shop/cart";
import { ProductImage } from "./ProductCard";

/**
 * The product photo on its page, viewable without zooming:
 *  - the whole picture is shown by default (nothing cut off);
 *  - turn it: swipe sideways across the picture, the buttons, or ← → keys
 *    (it settles on the nearest quarter turn when let go);
 *  - "Fill" shows it edge to edge, and then a finger or the mouse slides it around
 *    to see every part (↑ ↓ ← → keys too). The size of the picture never changes.
 */
export function ProductImageViewer({ product }: { product: Pick<ShopProduct, "name" | "image_url"> }) {
  const t = useT();
  const [angle, setAngle] = useState(0); // degrees; may grow past 360 so turns animate the short way
  const [turning, setTurning] = useState(false);
  const [fill, setFill] = useState(false);
  const [pos, setPos] = useState({ x: 50, y: 50 }); // object-position, in %
  const drag = useRef<{ x: number; y: number; startX: number; startAngle: number; size: number } | null>(null);
  const turn = Math.round(angle / 90);

  if (!product.image_url) return <ProductImage product={product} />;

  const clamp = (v: number) => Math.min(100, Math.max(0, v));
  const reset = () => {
    setAngle(0);
    setFill(false);
    setPos({ x: 50, y: 50 });
  };

  // Moves on screen become moves in the picture's own direction after turning.
  const move = (dx: number, dy: number) => {
    const q = ((turn % 4) + 4) % 4;
    const [ix, iy] = q === 0 ? [dx, dy] : q === 1 ? [dy, -dx] : q === 2 ? [-dx, -dy] : [-dy, dx];
    // Dragging the picture right shows its left side, as when sliding a photo.
    setPos((p) => ({ x: clamp(p.x - ix), y: clamp(p.y - iy) }));
  };

  const onPointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (e.button !== 0) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    drag.current = { x: e.clientX, y: e.clientY, startX: e.clientX, startAngle: angle, size: e.currentTarget.clientWidth || 300 };
  };
  const onPointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = drag.current;
    if (!d) return;
    if (!fill) {
      // Whole picture: sliding sideways turns it (half a turn across the full width).
      const delta = ((e.clientX - d.startX) / d.size) * 180;
      if (Math.abs(e.clientX - d.startX) > 4) setTurning(true);
      setAngle(d.startAngle + delta);
      return;
    }
    const dx = ((e.clientX - d.x) / d.size) * 100;
    const dy = ((e.clientY - d.y) / d.size) * 100;
    d.x = e.clientX;
    d.y = e.clientY;
    move(dx, dy);
  };
  const onPointerUp = () => {
    drag.current = null;
    setTurning(false);
    setAngle((a) => Math.round(a / 90) * 90); // settle upright or on its side
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    const step = 10;
    if (fill && ["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(e.key)) {
      e.preventDefault();
      move(e.key === "ArrowLeft" ? step : e.key === "ArrowRight" ? -step : 0, e.key === "ArrowUp" ? step : e.key === "ArrowDown" ? -step : 0);
    } else if (!fill && (e.key === "ArrowLeft" || e.key === "ArrowRight")) {
      e.preventDefault();
      setAngle((a) => a + (e.key === "ArrowRight" ? 90 : -90));
    }
  };

  const tool = "grid size-9 place-items-center rounded-full bg-surface/90 text-ink-800 shadow-sm ring-1 ring-ink-200 backdrop-blur transition hover:bg-surface focus-visible:outline-2 focus-visible:outline-brand-600";

  return (
    <div className="relative">
      <div
        role="img"
        aria-label={product.name}
        tabIndex={0}
        onKeyDown={onKeyDown}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
        onDoubleClick={() => setFill((f) => !f)}
        className={cn(
          "relative aspect-square w-full select-none overflow-hidden bg-white outline-none focus-visible:ring-2 focus-visible:ring-brand-600",
          fill ? "cursor-grab touch-none active:cursor-grabbing" : "cursor-ew-resize touch-pan-y",
        )}
      >
        {/* eslint-disable-next-line @next/next/no-img-element -- product photos come from the API's storage */}
        <img
          src={product.image_url}
          alt=""
          draggable={false}
          className={cn("pointer-events-none size-full", !turning && "transition-transform duration-300 ease-out", fill ? "object-cover" : "object-contain p-2")}
          style={{ transform: `rotate(${angle}deg)`, objectPosition: fill ? `${pos.x}% ${pos.y}%` : "50% 50%" }}
        />
        {fill ? (
          <span className="pointer-events-none absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-night-900/70 px-2 py-1 text-[11px] font-medium text-white">
            <Move className="size-3.5" /> {t("Slide to see all of it")}
          </span>
        ) : (
          <span className="pointer-events-none absolute left-2 top-2 rounded-full bg-night-900/60 px-2 py-1 text-[11px] font-medium text-white">
            {t("Swipe sideways to turn")}
          </span>
        )}
      </div>

      <div className="absolute bottom-2 right-2 flex gap-1.5">
        <button type="button" className={tool} onClick={() => setAngle((a) => a - 90)} aria-label={t("Turn left")} title={t("Turn left")}><RotateCcw className="size-4" /></button>
        <button type="button" className={tool} onClick={() => setAngle((a) => a + 90)} aria-label={t("Turn right")} title={t("Turn right")}><RotateCw className="size-4" /></button>
        <button
          type="button"
          className={tool}
          onClick={() => { setFill((f) => !f); setPos({ x: 50, y: 50 }); }}
          aria-pressed={fill}
          aria-label={fill ? t("Show the whole picture") : t("Fill the frame and slide around")}
          title={fill ? t("Show the whole picture") : t("Fill the frame and slide around")}
        >
          {fill ? <Shrink className="size-4" /> : <Expand className="size-4" />}
        </button>
        {(turn % 4 !== 0 || fill) && (
          <button type="button" className={cn(tool, "w-auto px-3 text-xs font-medium")} onClick={reset}>{t("Reset")}</button>
        )}
      </div>
    </div>
  );
}
