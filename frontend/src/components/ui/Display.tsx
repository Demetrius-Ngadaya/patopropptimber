"use client";

import { Loader2, Search } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Skeleton, SkeletonBlock, SkeletonCards, SkeletonPage, SkeletonStats } from "./Skeleton";

type Tone = "neutral" | "green" | "saffron" | "red" | "blue" | "navy";
const tones: Record<Tone, string> = {
  neutral: "bg-ink-100 text-ink-700",
  green: "bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-100",
  saffron: "bg-saffron-50 text-saffron-700 ring-1 ring-inset ring-saffron-100",
  red: "bg-ember-50 text-ember-700 ring-1 ring-inset ring-ember-100",
  blue: "bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-100",
  navy: "bg-night-800 text-white",
};

export function Badge({ tone = "neutral", children, className }: { tone?: Tone; children: React.ReactNode; className?: string }) {
  return <span className={cn("inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium", tones[tone], className)}>{children}</span>;
}

const statusTones: Record<string, Tone> = {
  paid: "green", completed: "green", received: "green", reconciled: "green", success: "green", sent: "green", active: "green",
  partial: "saffron", pending: "saffron", in_transit: "saffron", open: "saffron", processing: "blue", confirmed: "blue", ready: "blue", out_for_delivery: "blue",
  unpaid: "red", cancelled: "red", failed: "red", locked: "red", inactive: "red", expired: "red", overdue: "red",
};

export function StatusBadge({ status }: { status?: string | null }) {
  const t = useT();
  if (!status) return null;
  return <Badge tone={statusTones[status] ?? "neutral"}>{t(status.replace(/_/g, " "))}</Badge>;
}

export function Panel({ id, title, actions, children, className, padded = true }: { id?: string; title?: React.ReactNode; actions?: React.ReactNode; children: React.ReactNode; className?: string; padded?: boolean }) {
  return (
    <section id={id} className={cn("rounded-[var(--radius-panel)] bg-surface ring-1 ring-ink-200/70", className)}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-4 py-3">
          {title && <h2 className="text-[15px] font-semibold text-ink-900">{title}</h2>}
          {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
      )}
      <div className={padded ? "p-4" : undefined}>{children}</div>
    </section>
  );
}

export function PageHeader({ title, description, actions, back }: { title: string; description?: React.ReactNode; actions?: React.ReactNode; back?: React.ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0">
        {back}
        <h1 className="text-[22px] font-semibold tracking-tight text-ink-950">{title}</h1>
        {description && <p className="mt-1 max-w-2xl text-sm text-ink-500">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/**
 * The loading view: a skeleton shaped like what is coming (no "Loading…" text).
 *  - content (default): a panel of rows, for a list or details under a page header
 *  - page: the same with a title placeholder, for a whole page
 *  - compact: a few lines, for modals, panels and small areas
 *  - cards: a grid of cards (products, categories)
 *  - dashboard: stat tiles and two charts
 */
export function Spinner({ label, variant = "content" }: { label?: string; variant?: "content" | "page" | "compact" | "cards" | "dashboard" }) {
  if (variant === "compact") return <SkeletonBlock label={label} />;
  if (variant === "cards") return <SkeletonCards label={label} />;
  if (variant === "dashboard") {
    return (
      <div role="status" aria-busy="true">
        <SkeletonStats />
        <div className="mt-5 grid gap-5 lg:grid-cols-2"><Skeleton className="h-64 rounded-xl" /><Skeleton className="h-64 rounded-xl" /></div>
      </div>
    );
  }
  return <SkeletonPage label={label} header={variant === "page"} />;
}

/** A small turning icon, only for inline busy states (inside a button or a line of text). */
export function InlineSpinner({ className }: { className?: string }) {
  return <Loader2 aria-hidden className={cn("size-4 animate-spin", className)} />;
}

export function EmptyState({ icon, title, message, action }: { icon?: React.ReactNode; title: string; message?: string; action?: React.ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
      {icon && <div className="mb-3 text-ink-300">{icon}</div>}
      <p className="font-semibold text-ink-800">{title}</p>
      {message && <p className="mt-1 max-w-sm text-sm text-ink-500">{message}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const tr = useT();
  return (
    <div className="rounded-[var(--radius-panel)] border border-ember-100 bg-ember-50 px-4 py-3 text-sm text-ember-700" role="alert">
      {message}
      {onRetry && (
        <button type="button" onClick={onRetry} className="ml-2 font-semibold underline underline-offset-2">{tr("Try again")}</button>
      )}
    </div>
  );
}

export function SearchInput({ value, onChange, placeholder = "Search", className, autoFocus }: { value: string; onChange: (v: string) => void; placeholder?: string; className?: string; autoFocus?: boolean }) {
  return (
    <div className={cn("relative", className)}>
      <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" aria-hidden />
      <input
        type="search"
        value={value}
        autoFocus={autoFocus}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        className="h-10 w-full rounded-[var(--radius-control)] border border-ink-200 bg-surface pl-9 pr-3 text-sm placeholder:text-ink-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
      />
    </div>
  );
}

export function Stat({ label, value, sub, tone = "default" }: { label: string; value: React.ReactNode; sub?: React.ReactNode; tone?: "default" | "brand" | "saffron" | "ember" }) {
  const valueTone = { default: "text-ink-950", brand: "text-brand-700", saffron: "text-saffron-700", ember: "text-ember-600" }[tone];
  return (
    <div className="rounded-[var(--radius-panel)] bg-surface px-4 py-3.5 ring-1 ring-ink-200/70">
      <p className="text-[13px] text-ink-500">{label}</p>
      <p className={cn("num mt-1 text-[22px] font-semibold leading-tight tracking-tight", valueTone)}>{value}</p>
      {sub && <p className="mt-0.5 text-[12.5px] text-ink-500">{sub}</p>}
    </div>
  );
}

export function Tabs<T extends string>({ value, onChange, tabs }: { value: T; onChange: (v: T) => void; tabs: Array<{ value: T; label: string; count?: number }> }) {
  return (
    <div role="tablist" className="flex gap-1 overflow-x-auto border-b border-ink-200">
      {tabs.map((t) => (
        <button
          key={t.value}
          role="tab"
          type="button"
          aria-selected={value === t.value}
          onClick={() => onChange(t.value)}
          className={cn(
            "-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors",
            value === t.value ? "border-brand-600 text-brand-700" : "border-transparent text-ink-500 hover:text-ink-800",
          )}
        >
          {t.label}
          {t.count !== undefined && <span className="num ml-1.5 rounded-full bg-ink-100 px-1.5 text-xs text-ink-600">{t.count}</span>}
        </button>
      ))}
    </div>
  );
}

export function DescriptionList({ items }: { items: Array<[string, React.ReactNode]> }) {
  const t = useT();
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
      {items.map(([k, v]) => (
        <div key={k} className="min-w-0">
          <dt className="text-[12.5px] text-ink-500">{t(k)}</dt>
          <dd className="mt-0.5 break-words text-sm text-ink-900">{v ?? "—"}</dd>
        </div>
      ))}
    </dl>
  );
}
