"use client";

import { useEffect, useState } from "react";
import type { ApiResponse } from "@/lib/api";
import { cn } from "@/lib/format";
import { useApi } from "@/lib/hooks";

export type LoginSlide = { id: number; title: string | null; caption: string | null; image_url: string; image_small_url?: string | null };

const SHOW_MS = 7000;
const FADE_MS = 1800;

/**
 * Admin-managed pictures on the login page. Slides cross-fade slowly while
 * gently zooming, so the change is barely noticed. Pauses for users who
 * prefer reduced motion, and shows a calm brand backdrop when there are no slides.
 */
export function LoginSlider({ place = "login", className, onEmpty }: { place?: "login" | "shop"; className?: string; onEmpty?: React.ReactNode } = {}) {
  const q = useApi<ApiResponse<LoginSlide[]>>("public/login-slides", place === "shop" ? { place } : undefined);
  const slides = q.data?.data ?? [];
  const [index, setIndex] = useState(0);
  const [reduced, setReduced] = useState(false);

  useEffect(() => {
    const m = window.matchMedia("(prefers-reduced-motion: reduce)");
    setReduced(m.matches);
  }, []);

  useEffect(() => {
    if (slides.length < 2 || reduced) return;
    const timer = setInterval(() => setIndex((i) => (i + 1) % slides.length), SHOW_MS);
    return () => clearInterval(timer);
  }, [slides.length, reduced]);

  // Warm the cache for the next picture so the fade never shows a blank frame.
  useEffect(() => {
    if (slides.length < 2) return;
    const next = slides[(index + 1) % slides.length];
    const img = new Image();
    img.src = window.innerWidth < 1000 && next.image_small_url ? next.image_small_url : next.image_url;
  }, [index, slides]);

  if (!slides.length && onEmpty !== undefined) return q.isLoading ? null : <>{onEmpty}</>;
  if (!slides.length) {
    return (
      <div aria-hidden className="absolute inset-0 bg-night-900">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgb(227_160_21/0.25),transparent_45%),radial-gradient(circle_at_80%_70%,rgb(31_148_104/0.35),transparent_50%)]" />
        <div className="absolute inset-0 opacity-[0.07] [background-image:repeating-linear-gradient(45deg,#fff_0_1px,transparent_1px_14px)]" />
      </div>
    );
  }

  const current = slides[index];

  return (
    <div className={cn("absolute inset-0 overflow-hidden bg-night-950", className)} role="region" aria-roledescription="carousel" aria-label={current.title ?? undefined}>
      {slides.map((slide, i) => (
        <div
          key={slide.id}
          aria-hidden={i !== index}
          className="absolute inset-0 transition-opacity ease-in-out"
          style={{ opacity: i === index ? 1 : 0, transitionDuration: `${FADE_MS}ms` }}
        >
          {/* eslint-disable-next-line @next/next/no-img-element -- served from the API storage */}
          <img
            src={slide.image_url}
            // Phones download the small copy (~3× lighter); big screens the full one.
            srcSet={slide.image_small_url && slide.image_small_url !== slide.image_url ? `${slide.image_small_url} 960w, ${slide.image_url} 1920w` : undefined}
            sizes={place === "shop" ? "(max-width: 1100px) 100vw, 1100px" : "(max-width: 1024px) 100vw, 55vw"}
            alt={slide.title ?? ""}
            className={cn("size-full object-cover", i === index && !reduced && "slide-zoom")}
            loading={i === 0 ? "eager" : "lazy"}
            fetchPriority={i === 0 ? "high" : "low"}
            decoding="async"
          />
        </div>
      ))}
      {/* Soft shade so an optional caption stays readable on any picture. */}
      <div className="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/55 to-transparent" />
      {(current.title || current.caption) && (
        <div key={current.id} className="absolute inset-x-10 bottom-14 max-w-lg text-white transition-opacity duration-700">
          {current.title && <p className="text-2xl font-semibold tracking-tight drop-shadow">{current.title}</p>}
          {current.caption && <p className="mt-1.5 text-white/85 drop-shadow">{current.caption}</p>}
        </div>
      )}
      {slides.length > 1 && (
        <div className="absolute bottom-6 left-10 flex gap-1.5">
          {slides.map((s, i) => (
            <button
              key={s.id}
              type="button"
              aria-label={`${i + 1} / ${slides.length}`}
              aria-current={i === index}
              onClick={() => setIndex(i)}
              className={cn("h-1.5 rounded-full transition-all duration-700", i === index ? "w-6 bg-white" : "w-1.5 bg-white/45 hover:bg-white/70")}
            />
          ))}
        </div>
      )}
    </div>
  );
}
