"use client";

import { ChevronLeft, ChevronRight, Download, Inbox } from "lucide-react";
import { cn, money, qty, toNumber } from "@/lib/format";
import { ALL_ROWS, PAGE_SIZES, pathOf, serialOf, setTableSize, useTableSize } from "@/lib/table-size";
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
  /**
   * Total under the column. Right-aligned number columns get one automatically;
   * false turns it off, "money" / "number" force the format.
   */
  total?: boolean | "money" | "number";
  /** The number used for the total (default: the value at `key`). */
  value?: (row: T) => number | string | null | undefined;
  /** A total worked out elsewhere (e.g. for all filtered records) shown instead of adding up the rows. */
  totalValue?: number | string | null;
  /** Text for the downloaded file (default: the value at `key`). */
  exportValue?: (row: T) => string | number | null | undefined;
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
  /** S/N column (row number across pages). On by default. */
  serial?: boolean;
  /** Totals row under number columns. On by default. */
  totals?: boolean;
  /** "Download" button for the rows shown. On by default; a string sets the file name. */
  exportable?: boolean | string;
};

function raw<T>(row: T, key: string): unknown {
  return key.split(".").reduce<unknown>((acc, part) => (acc == null ? acc : (acc as Record<string, unknown>)[part]), row);
}

function cell<T>(row: T, key: string): React.ReactNode {
  const value = raw(row, key);
  if (value === null || value === undefined || value === "") return <span className="text-ink-300">—</span>;
  return String(value);
}

const NUMERIC = /^-?\d+(\.\d+)?$/;
const MONEY = /^-?\d+\.\d{2}$/;

/** Which columns get a total, and in what format. */
function totalPlan<T>(columns: Column<T>[], rows: T[]) {
  return columns.map((c) => {
    // "_" columns are buttons / checkboxes unless they say how to total them.
    if (c.total === false || (c.key.startsWith("_") && !c.total)) return null;
    if (!c.total && c.align !== "right") return null;
    const values = rows.map((r) => (c.value ? c.value(r) : raw(r, c.key)));
    const present = values.filter((v) => v !== null && v !== undefined && v !== "");
    if (c.totalValue === undefined || c.totalValue === null) {
      if (!present.length || !present.every((v) => typeof v === "number" || (typeof v === "string" && NUMERIC.test(v.trim())))) return null;
    }
    const format = c.total === "money" || c.total === "number" ? c.total
      : present.some((v) => typeof v === "string" && MONEY.test(v.trim())) ? "money" : "number";
    const sum = c.totalValue !== undefined && c.totalValue !== null ? toNumber(c.totalValue) : present.reduce<number>((a, v) => a + toNumber(v), 0);
    return { format, sum };
  });
}

function headerText(header: React.ReactNode): string | null {
  return typeof header === "string" || typeof header === "number" ? String(header) : null;
}

function csvEscape(v: unknown): string {
  const s = v === null || v === undefined ? "" : String(v);
  return /[",\n\r;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function downloadCsv<T>(name: string, columns: Column<T>[], rows: T[], serial: boolean, plan: ReturnType<typeof totalPlan<T>>, labels: { sn: string; total: string }) {
  const cols = columns.map((c, i) => ({ c, i, title: headerText(c.header) })).filter((x) => x.title && (!x.c.key.startsWith("_") || x.c.exportValue));
  const lines: string[] = [];
  lines.push([...(serial ? [labels.sn] : []), ...cols.map((x) => x.title)].map(csvEscape).join(","));
  rows.forEach((r, i) => {
    lines.push([...(serial ? [serialOf(r, i)] : []), ...cols.map(({ c }) => (c.exportValue ? c.exportValue(r) : raw(r, c.key)))].map((v) => csvEscape(typeof v === "object" ? "" : v)).join(","));
  });
  if (plan.some(Boolean)) {
    const totalRow = cols.map(({ i }) => (plan[i] ? Math.round(plan[i]!.sum * 100) / 100 : ""));
    lines.push([...(serial ? [labels.total] : []), ...(serial ? totalRow : [labels.total, ...totalRow.slice(1)])].map(csvEscape).join(","));
  }
  // The BOM makes Excel read Swahili / special characters correctly.
  const blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `${name.replace(/[^\p{L}\p{N} _-]+/gu, "").trim() || "data"} ${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function DataTable<T>({ columns, rows, loading, rowKey, onRowClick, empty, footer, rowClassName, serial = true, totals = true, exportable = true }: Props<T>) {
  const t = useT();
  if (loading && !rows) return <SkeletonTable columns={Math.min(columns.length, 6)} />;
  if (!rows?.length) {
    return <EmptyState icon={<Inbox className="size-9" />} title={empty?.title ?? t("Nothing here yet")} message={empty?.message} action={empty?.action} />;
  }

  const plan = totals && !footer ? totalPlan(columns, rows) : [];
  const showTotals = plan.some(Boolean);
  const firstTotal = plan.findIndex(Boolean);
  // A column given its total from the server covers every page, not just these rows.
  const allPages = columns.some((c, i) => plan[i] && c.totalValue !== undefined && c.totalValue !== null);
  const fileName = typeof exportable === "string" ? exportable : (typeof document !== "undefined" ? document.title.split("|")[0].split("·")[0] : "data");

  return (
    <div className={cn("overflow-x-auto", loading && "opacity-60 transition-opacity")}>
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-b border-ink-200 bg-ink-50/60">
            {serial && <th scope="col" className="w-10 whitespace-nowrap px-3 py-2.5 text-left text-[12.5px] font-semibold text-ink-600">{t("S/N")}</th>}
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
              {serial && <td className="num px-3 py-2.5 align-middle text-xs text-ink-500">{serialOf(row, i)}</td>}
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
        {showTotals && (
          <tfoot>
            <tr className="border-t-2 border-ink-200 bg-ink-50/80 font-semibold text-ink-900">
              {serial && <td className="px-3 py-2.5" />}
              {columns.map((c, i) => {
                const p = plan[i];
                const label = !p && i === Math.max(0, firstTotal - 1) && (serial || firstTotal > 0);
                return (
                  <td key={c.key} className={cn("px-3 py-2.5", c.align === "right" && "num text-right", c.hideOnMobile && "hidden md:table-cell", label && "text-ink-600")}>
                    {p ? (p.format === "money" ? money(p.sum) : qty(p.sum)) : label ? (allPages ? t("Total (all pages)") : t("Total")) : null}
                  </td>
                );
              })}
            </tr>
          </tfoot>
        )}
      </table>
      {exportable && (
        <div className="flex justify-end border-t border-ink-100 px-3 py-1.5">
          <button
            type="button"
            onClick={() => downloadCsv(fileName, columns, rows, serial, plan.length ? plan : totalPlan(columns, rows), { sn: t("S/N"), total: t("Total") })}
            className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-ink-600 hover:bg-ink-100 hover:text-ink-900"
            title={t("Download the rows shown (with the filters and rows-per-page you chose) as an Excel / CSV file")}
          >
            <Download className="size-3.5" />
            {t("Download ({count} rows)", { count: rows.length })}
          </button>
        </div>
      )}
    </div>
  );
}

export function Pagination({ meta, onPage }: { meta?: { current_page: number; last_page: number; total: number; per_page: number }; onPage: (p: number) => void }) {
  const t = useT();
  const path = pathOf(meta);
  const chosen = useTableSize(path);
  if (!meta || meta.total === 0) return null;
  const from = (meta.current_page - 1) * meta.per_page + 1;
  const to = Math.min(meta.current_page * meta.per_page, meta.total);
  const current = chosen ?? meta.per_page;
  const showAll = current >= ALL_ROWS;
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-ink-100 px-4 py-3 text-sm text-ink-600">
      <div className="flex items-center gap-3">
        <span className="num">
          {t("{from}–{to} of {total}", { from: from.toLocaleString(), to: to.toLocaleString(), total: meta.total.toLocaleString() })}
        </span>
        {path && (
          <label className="flex items-center gap-1.5 text-xs text-ink-500">
            <span>{t("Rows per page")}</span>
            <select
              className="rounded-md border border-ink-200 bg-surface px-1.5 py-1 text-xs text-ink-800 focus:border-brand-500 focus:outline-none"
              value={showAll ? "all" : String(current)}
              onChange={(e) => {
                setTableSize(path, e.target.value === "all" ? ALL_ROWS : Number(e.target.value));
                onPage(1);
              }}
            >
              {PAGE_SIZES.map((n) => <option key={n} value={n}>{n}</option>)}
              {!PAGE_SIZES.includes(current as (typeof PAGE_SIZES)[number]) && !showAll && <option value={current}>{current}</option>}
              <option value="all">{t("All")}</option>
            </select>
          </label>
        )}
      </div>
      {meta.last_page > 1 && (
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
      )}
    </div>
  );
}
