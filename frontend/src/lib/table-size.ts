"use client";

import { useSyncExternalStore } from "react";

/**
 * Rows per page chosen by the user, remembered per list (API path) in this browser.
 * `ALL_ROWS` asks the API for everything that matches the filters (it caps very large lists).
 */
export const PAGE_SIZES = [10, 25, 50, 100] as const;
export const ALL_ROWS = 5000;

const STORAGE_KEY = "patopro.table-size";
const listeners = new Set<() => void>();
let sizes: Record<string, number> | null = null;

function load(): Record<string, number> {
  if (sizes) return sizes;
  sizes = {};
  try {
    const raw = typeof window !== "undefined" ? window.localStorage.getItem(STORAGE_KEY) : null;
    if (raw) sizes = JSON.parse(raw) as Record<string, number>;
  } catch {
    sizes = {};
  }
  return sizes;
}

export function getTableSize(path: string | null | undefined): number | undefined {
  if (!path) return undefined;
  return load()[path];
}

export function setTableSize(path: string, size: number) {
  sizes = { ...load(), [path]: size };
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(sizes));
  } catch {
    // Private mode: the choice lasts for this visit only.
  }
  listeners.forEach((l) => l());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** The page size chosen for a list, or undefined for the API's default (25). */
export function useTableSize(path: string | null | undefined): number | undefined {
  return useSyncExternalStore(
    subscribe,
    () => getTableSize(path),
    () => undefined,
  );
}

/**
 * Adds row numbers and the list's path to a paginated API response, without changing
 * what the page sees: DataTable reads `__sn`, Pagination reads `__path`.
 */
export function annotatePage<T>(path: string, response: T): T {
  const r = response as unknown as { data?: unknown; meta?: { current_page?: number; per_page?: number } };
  if (!r || typeof r !== "object" || !Array.isArray(r.data) || !r.meta || typeof r.meta.current_page !== "number") return response;
  const offset = (r.meta.current_page - 1) * (r.meta.per_page ?? r.data.length);
  r.data.forEach((row, i) => {
    if (row && typeof row === "object" && !Object.isFrozen(row)) {
      Object.defineProperty(row, "__sn", { value: offset + i + 1, enumerable: false, configurable: true });
    }
  });
  Object.defineProperty(r.meta, "__path", { value: path, enumerable: false, configurable: true });
  return response;
}

export function serialOf(row: unknown, index: number): number {
  const sn = (row as { __sn?: number } | null)?.__sn;
  return typeof sn === "number" ? sn : index + 1;
}

export function pathOf(meta: unknown): string | undefined {
  return (meta as { __path?: string } | null)?.__path;
}
