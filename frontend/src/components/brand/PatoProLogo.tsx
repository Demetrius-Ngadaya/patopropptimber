import { BRAND } from "@/lib/brand";
import { cn } from "@/lib/format";

/**
 * The PatoPro app icon: growing bars and a rising arrow on SHEZONI blue.
 * The same drawing is used for the favicon and the installed-app icons
 * (src/app/icon.svg, public/icons/*), so keep them in step.
 */
export function PatoProMark({ className, title = BRAND.product }: { className?: string; title?: string }) {
  return (
    <svg viewBox="0 0 64 64" role="img" aria-label={title} className={cn("shrink-0", className)}>
      <rect width="64" height="64" rx="14" fill={BRAND.blue} />
      <rect x="14" y="40" width="8" height="12" rx="2" fill="#fff" opacity="0.8" />
      <rect x="28" y="34" width="8" height="18" rx="2" fill="#fff" opacity="0.9" />
      <rect x="42" y="27" width="8" height="25" rx="2" fill="#fff" />
      <path d="M12 30 L24 21 L32 25 L49 12" fill="none" stroke={BRAND.greenOnDark} strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M41 12 H49 V20" fill="none" stroke={BRAND.greenOnDark} strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/** The "o" of "Pato": a ring with three growing bars inside. Sized to the text (1em). */
function GrowthO() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden className="mx-[0.01em] inline-block h-[0.6em] w-[0.6em] align-baseline">
      <circle cx="12" cy="12" r="9.4" fill="none" stroke="currentColor" strokeWidth="3.4" />
      <rect x="7" y="13" width="2.6" height="4" rx="0.6" className="fill-[var(--pato-green)]" />
      <rect x="10.7" y="10.5" width="2.6" height="6.5" rx="0.6" className="fill-[var(--pato-green)]" />
      <rect x="14.4" y="8" width="2.6" height="9" rx="0.6" className="fill-[var(--pato-green)]" />
    </svg>
  );
}

/**
 * The wordmark: "Pato" bold in SHEZONI blue (with the growth "o"), "Pro" lighter in green.
 * Readable on light and dark backgrounds.
 */
export function PatoProWordmark({ className }: { className?: string }) {
  return (
    <span
      aria-label={BRAND.product}
      role="img"
      className={cn(
        "inline-flex items-baseline whitespace-nowrap leading-none tracking-tight",
        "[--pato-blue:#0B3D91] [--pato-green:#16A34A] dark:[--pato-blue:#7FA8F5] dark:[--pato-green:#4ADE80]",
        className,
      )}
    >
      <span aria-hidden className="font-extrabold text-[var(--pato-blue)]">Pat<GrowthO /></span>
      <span aria-hidden className="font-light text-[var(--pato-green)]">Pro</span>
    </span>
  );
}

/** "Powered by SHEZONI PatoPro": small print for receipts, invoices and public pages. */
export function PoweredBy({ className }: { className?: string }) {
  return <p className={cn("text-center text-[10.5px] text-ink-400", className)}>{BRAND.poweredBy}</p>;
}
