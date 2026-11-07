"use client";

import { cn } from "@/lib/format";
import { usePublicSettings } from "@/lib/public-settings";
import { BRAND } from "@/lib/brand";

const SIZES = {
  xs: "size-7 text-[11px]",
  sm: "size-9 text-[13px]",
  md: "size-12 text-base",
  lg: "size-20 text-2xl",
  xl: "size-24 text-3xl",
} as const;

function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  return (words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? "M").slice(0, 2)).toUpperCase();
}

/**
 * The business logo, always shown as a circle (sidebar, login, shop, receipts).
 * Falls back to the business initials when no logo has been uploaded.
 */
export function BrandLogo({ size = "sm", className, ring = true }: { size?: keyof typeof SIZES; className?: string; ring?: boolean }) {
  const { settings } = usePublicSettings();
  const name = settings?.business_name || BRAND.product;
  const ringClass = ring ? "ring-2 ring-white/80 shadow-sm" : "";

  if (settings?.business_logo_url) {
    return (
      <span className={cn("inline-block shrink-0 overflow-hidden rounded-full bg-white", SIZES[size], ringClass, className)}>
        {/* eslint-disable-next-line @next/next/no-img-element -- served from the API storage */}
        <img src={settings.business_logo_url} alt={name} className="size-full object-cover" />
      </span>
    );
  }

  return (
    <span aria-label={name} role="img" className={cn("grid shrink-0 place-items-center rounded-full bg-saffron-500 font-bold text-night-950", SIZES[size], ringClass, className)}>
      {initials(name)}
    </span>
  );
}

export function useBusinessName(): string {
  const { settings } = usePublicSettings();
  return settings?.business_name || BRAND.product;
}
