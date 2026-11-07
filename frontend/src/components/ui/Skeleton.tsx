"use client";

import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";

/**
 * Loading placeholders shaped like the content that is coming, instead of a
 * "Loading…" message. Every skeleton is announced once to screen readers
 * (role="status" + a hidden label) and pulses gently (reduced motion: static).
 */

/** One grey block. Size it with className (h-4 w-32, size-10 rounded-full...). */
export function Skeleton({ className }: { className?: string }) {
  return <span aria-hidden className={cn("block animate-pulse rounded-md bg-ink-200/70 motion-reduce:animate-none dark:bg-ink-700/50", className)} />;
}

function Status({ label, className, children }: { label?: string; className?: string; children: React.ReactNode }) {
  const t = useT();
  return (
    <div role="status" aria-live="polite" aria-busy="true" className={className}>
      <span className="sr-only">{t(label ?? "Loading")}</span>
      {children}
    </div>
  );
}

/** Lines of text; the last one shorter, like a paragraph. */
export function SkeletonText({ lines = 3, className }: { lines?: number; className?: string }) {
  return (
    <div aria-hidden className={cn("space-y-2", className)}>
      {Array.from({ length: lines }, (_, i) => (
        <Skeleton key={i} className={cn("h-3.5", i === lines - 1 && lines > 1 ? "w-3/5" : "w-full")} />
      ))}
    </div>
  );
}

const WIDTHS = ["w-24", "w-40", "w-28", "w-20", "w-32", "w-16"];

/** A table: header row and a few body rows. */
export function SkeletonTable({ rows = 6, columns = 5, className, label }: { rows?: number; columns?: number; className?: string; label?: string }) {
  return (
    <Status label={label} className={cn("overflow-hidden", className)}>
      <div className="flex gap-6 border-b border-ink-200 bg-ink-50/60 px-3 py-3">
        {Array.from({ length: columns }, (_, c) => <Skeleton key={c} className={cn("h-3", WIDTHS[c % WIDTHS.length], c > 2 && "hidden md:block")} />)}
      </div>
      {Array.from({ length: rows }, (_, r) => (
        <div key={r} className="flex items-center gap-6 border-b border-ink-100 px-3 py-3.5 last:border-0">
          {Array.from({ length: columns }, (_, c) => (
            <Skeleton key={c} className={cn("h-3.5", WIDTHS[(c + r) % WIDTHS.length], c === columns - 1 && "ml-auto", c > 2 && "hidden md:block")} />
          ))}
        </div>
      ))}
    </Status>
  );
}

/** Stat tiles (dashboard, report summaries). */
export function SkeletonStats({ count = 4, className }: { count?: number; className?: string }) {
  return (
    <div aria-hidden className={cn("grid gap-3 sm:grid-cols-2 xl:grid-cols-4", className)}>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="rounded-xl border border-ink-200 bg-surface p-4">
          <Skeleton className="h-3 w-24" />
          <Skeleton className="mt-3 h-7 w-32" />
          <Skeleton className="mt-2 h-3 w-20" />
        </div>
      ))}
    </div>
  );
}

/** A grid of product / category cards (POS, online shop). */
export function SkeletonCards({ count = 8, className, label }: { count?: number; className?: string; label?: string }) {
  return (
    <Status label={label} className={cn("grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4", className)}>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="overflow-hidden rounded-xl border border-ink-200 bg-surface">
          <Skeleton className="aspect-[4/3] rounded-none" />
          <div className="space-y-2 p-3">
            <Skeleton className="h-3.5 w-4/5" />
            <Skeleton className="h-3.5 w-1/2" />
          </div>
        </div>
      ))}
    </Status>
  );
}

/** A form: labels and fields. */
export function SkeletonForm({ fields = 5, className, label }: { fields?: number; className?: string; label?: string }) {
  return (
    <Status label={label} className={cn("space-y-4", className)}>
      {Array.from({ length: fields }, (_, i) => (
        <div key={i}>
          <Skeleton className="h-3 w-28" />
          <Skeleton className="mt-2 h-10 w-full" />
        </div>
      ))}
    </Status>
  );
}

/** A page: title, a line of description, then a panel of rows. The default loading view. */
export function SkeletonPage({ className, label, rows = 6, header = true }: { className?: string; label?: string; rows?: number; header?: boolean }) {
  return (
    <Status label={label} className={cn("py-1", className)}>
      {header && (
        <div aria-hidden className="mb-5">
          <Skeleton className="h-6 w-56" />
          <Skeleton className="mt-2.5 h-3.5 w-80 max-w-full" />
        </div>
      )}
      <div aria-hidden className="overflow-hidden rounded-xl border border-ink-200 bg-surface">
        <div className="flex gap-6 border-b border-ink-200 px-3 py-3">
          {[0, 1, 2, 3].map((c) => <Skeleton key={c} className={cn("h-3", WIDTHS[c], c > 1 && "hidden md:block")} />)}
        </div>
        {Array.from({ length: rows }, (_, r) => (
          <div key={r} className="flex items-center gap-6 border-b border-ink-100 px-3 py-3.5 last:border-0">
            {[0, 1, 2, 3].map((c) => <Skeleton key={c} className={cn("h-3.5", WIDTHS[(c + r) % WIDTHS.length], c === 3 && "ml-auto", c > 1 && "hidden md:block")} />)}
          </div>
        ))}
      </div>
    </Status>
  );
}

/** A compact block for panels, modals and small areas. */
export function SkeletonBlock({ className, label, lines = 4 }: { className?: string; label?: string; lines?: number }) {
  return (
    <Status label={label} className={cn("py-3", className)}>
      <SkeletonText lines={lines} />
    </Status>
  );
}

/** The whole back office while the session is checked: sidebar, top bar and a page. */
export function SkeletonShell() {
  return (
    <Status label="Opening your workspace" className="min-h-dvh lg:pl-60">
      <aside aria-hidden className="keep-light fixed inset-y-0 left-0 hidden w-60 bg-night-900 p-4 lg:block">
        <div className="flex items-center gap-2.5">
          <span className="size-9 animate-pulse rounded-full bg-white/10 motion-reduce:animate-none" />
          <span className="h-4 w-28 animate-pulse rounded bg-white/10 motion-reduce:animate-none" />
        </div>
        <span className="mt-6 block h-9 animate-pulse rounded-md bg-white/10 motion-reduce:animate-none" />
        <div className="mt-6 space-y-3">
          {Array.from({ length: 12 }, (_, i) => (
            <span key={i} className={cn("block h-3.5 animate-pulse rounded bg-white/10 motion-reduce:animate-none", i % 4 === 0 ? "mt-5 w-16" : "w-36")} />
          ))}
        </div>
      </aside>
      <div aria-hidden className="flex h-14 items-center justify-between border-b border-ink-200 bg-surface px-4">
        <Skeleton className="h-8 w-44" />
        <div className="flex items-center gap-2">
          <Skeleton className="size-8 rounded-full" />
          <Skeleton className="size-8 rounded-full" />
          <Skeleton className="h-8 w-28 rounded-full" />
        </div>
      </div>
      <div aria-hidden className="mx-auto w-full max-w-[1500px] px-4 py-5 sm:px-6">
        <Skeleton className="h-6 w-60" />
        <Skeleton className="mt-2.5 h-3.5 w-72 max-w-full" />
        <SkeletonStats className="mt-6" />
        <div className="mt-5 grid gap-5 lg:grid-cols-2">
          <Skeleton className="h-64 rounded-xl" />
          <Skeleton className="h-64 rounded-xl" />
        </div>
      </div>
    </Status>
  );
}

/** A receipt (till printout). */
export function SkeletonReceipt() {
  return (
    <Status label="Preparing receipt" className="mx-auto mt-6 w-[300px] rounded bg-white p-4 shadow">
      <Skeleton className="mx-auto h-4 w-40" />
      <Skeleton className="mx-auto mt-2 h-3 w-48" />
      <Skeleton className="mx-auto mt-1.5 h-3 w-36" />
      <div className="my-4 border-t border-dashed border-ink-200" />
      <SkeletonText lines={8} />
      <div className="my-4 border-t border-dashed border-ink-200" />
      <Skeleton className="h-5 w-full" />
    </Status>
  );
}
