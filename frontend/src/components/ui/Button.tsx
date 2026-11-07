"use client";

import { forwardRef } from "react";
import { Loader2 } from "lucide-react";
import Link from "next/link";
import { cn } from "@/lib/format";

type Variant = "primary" | "secondary" | "ghost" | "danger" | "saffron";
type Size = "sm" | "md" | "lg";

const variants: Record<Variant, string> = {
  primary: "bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 disabled:bg-brand-600/50",
  secondary: "bg-surface text-ink-800 ring-1 ring-inset ring-ink-200 hover:bg-ink-50 hover:ring-ink-300 disabled:text-ink-400",
  ghost: "text-ink-700 hover:bg-ink-100 disabled:text-ink-400",
  danger: "bg-ember-600 text-white hover:bg-ember-700 disabled:bg-ember-600/50",
  saffron: "bg-saffron-500 text-ink-950 hover:bg-saffron-600 disabled:bg-saffron-500/50",
};

const sizes: Record<Size, string> = {
  sm: "h-8 px-3 text-[13px] gap-1.5",
  md: "h-10 px-4 text-sm gap-2",
  lg: "h-12 px-5 text-base gap-2",
};

export type ButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: React.ReactNode;
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = "primary", size = "md", loading, icon, className, children, disabled, type = "button", ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(
        "inline-flex shrink-0 items-center justify-center rounded-[var(--radius-control)] font-semibold transition-colors disabled:cursor-not-allowed",
        variants[variant],
        sizes[size],
        className,
      )}
      {...rest}
    >
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : icon}
      {children}
    </button>
  );
});

/** A link that looks like a button (never nest a <button> inside an <a>). */
export function ButtonLink({ href, variant = "primary", size = "md", icon, className, children }: { href: string; variant?: Variant; size?: Size; icon?: React.ReactNode; className?: string; children: React.ReactNode }) {
  return (
    <Link href={href} className={cn("inline-flex shrink-0 items-center justify-center rounded-[var(--radius-control)] font-semibold transition-colors", variants[variant], sizes[size], className)}>
      {icon}
      {children}
    </Link>
  );
}
