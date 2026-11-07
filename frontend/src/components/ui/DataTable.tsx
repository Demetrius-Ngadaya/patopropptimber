"use client";

import { ChevronLeft, ChevronRight, Inbox } from "lucide-react";
import { cn } from "@/lib/format";
import { EmptyState } from "./Display";
import { SkeletonTable } from "./Skeleton";
import { useT } from "@/lib/i18n";

export type Column<T> = {
  key: string;
  header: React.ReactNode;
  render?: (row: T) => React.ReactNode;
  align?: "left" | "right" | "center";
  className?: string;
  /** Hide on small screens to keep tables readable on phones. */
  hideOnMobile?: boolean;
};

type Props<T> = {
  columns: Column<T>[];
  rows: T[] | undefined;
  loading?: boolean;
  rowKey?: (row: T) => string | number;
  onRowClick?: (row: T) => void;
  empty?: { title: string; message?: string; action?: React.ReactNode };
  footer?: React.ReactNode;
  rowClassName?: (row: T) => string | undefined;
};

function cell<T>(row: T, key: string): React.ReactNode {
  const value = key.split(".").reduce<unknown>((acc, part) => (acc == null ? acc : (acc as Record<string, unknown>)[part]), row);
  if (value === null || value === undefined || value === "") return <span className="text-ink-300">—</span>;
  return String(value);
}

export function DataTable<T>({ columns, rows, loading, rowKey, onRowClick, empty, footer, rowClassName }: Props<T>) {
  const t = useT();
  if (loading && !rows) return <SkeletonTable columns={Math.min(columns.length, 6)} />;
  if (!rows?.length) {
    return <EmptyState icon={<Inbox className="size-9" />} title={empty?.title ?? t("Nothing here yet")} message={empty?.message} action={empty?.action} />;
  }

  return (
    <div className={cn("overflow-x-auto", loading && "opacity-60 transition-opacity")}>
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-b border-ink-200 bg-ink-50/60">
            {columns.map((c) => (
              <th
                key={c.key}
                scope="col"
                className={cn(
                  "whitespace-nowrap px-3 py-2.5 text-left text-[12.5px] font-semibold text-ink-600",
                  c.align === "right" && "text-right",
                  c.align === "center" && "text-center",
                  c.hideOnMobile && "hidden md:table-cell",
                )}
              >
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr
              key={rowKey ? rowKey(row) : i}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              onKeyDown={onRowClick ? (e) => e.key === "Enter" && onRowClick(row) : undefined}
              tabIndex={onRowClick ? 0 : undefined}
              className={cn("border-b border-ink-100 last:border-0", onRowClick && "cursor-pointer hover:bg-brand-50/40 focus:bg-brand-50/60 focus:outline-none", rowClassName?.(row))}
            >
              {columns.map((c) => (
                <td
                  key={c.key}
                  className={cn(
                    "px-3 py-2.5 align-middle text-ink-800",
                    c.align === "right" && "num text-right",
                    c.align === "center" && "text-center",
                    c.hideOnMobile && "hidden md:table-cell",
                    c.className,
                  )}
                >
                  {c.render ? c.render(row) : cell(row, c.key)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
        {footer}
      </table>
    </div>
  );
}

export function Pagination({ meta, onPage }: { meta?: { current_page: number; last_page: number; total: number; per_page: number }; onPage: (p: number) => void }) {
  const t = useT();
  if (!meta || meta.total === 0) return null;
  const from = (meta.current_page - 1) * meta.per_page + 1;
  const to = Math.min(meta.current_page * meta.per_page, meta.total);
  return (
    <div className="flex items-center justify-between gap-3 border-t border-ink-100 px-4 py-3 text-sm text-ink-600">
      <span className="num">
        {t("{from}–{to} of {total}", { from: from.toLocaleString(), to: to.toLocaleString(), total: meta.total.toLocaleString() })}
      </span>
      <div className="flex items-center gap-1">
        <button
          type="button"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          className="rounded-md p-1.5 hover:bg-ink-100 disabled:opacity-40"
          aria-label={t("Previous page")}
        >
          <ChevronLeft className="size-4" />
        </button>
        <span className="num px-2">
          {meta.current_page} / {meta.last_page}
        </span>
        <button
          type="button"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          className="rounded-md p-1.5 hover:bg-ink-100 disabled:opacity-40"
          aria-label={t("Next page")}
        >
          <ChevronRight className="size-4" />
        </button>
      </div>
    </div>
  );
}
