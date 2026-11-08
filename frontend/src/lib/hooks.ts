"use client";

import { replaceEqualDeep, useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, ApiError, type ApiResponse, type Paginated } from "./api";
import { useT } from "./i18n";
import { annotatePage, useTableSize } from "./table-size";

/**
 * GET with caching. `query` becomes part of the cache key.
 * Lists remember the rows-per-page the user picked (Pagination's selector) and get
 * row numbers for the S/N column.
 */
export function useApi<T>(path: string | null, query?: Record<string, string | number | boolean | null | undefined>, options?: { enabled?: boolean; keepPrevious?: boolean }) {
  const size = useTableSize(path);
  const effective = size && !(query && "per_page" in query) ? { ...(query ?? {}), per_page: size } : query;
  return useQuery<T, ApiError>({
    queryKey: [path, effective ?? {}],
    queryFn: ({ signal }) => api.get<T>(path as string, effective, signal),
    // Row numbers and the list's path are added after structural sharing, which copies
    // changed objects (and would drop them).
    structuralSharing: (oldData, newData) => annotatePage(path as string, replaceEqualDeep(oldData, newData)),
    enabled: Boolean(path) && (options?.enabled ?? true),
    placeholderData: options?.keepPrevious === false ? undefined : (prev) => prev,
  });
}

export function usePaginated<T>(path: string, query?: Record<string, string | number | boolean | null | undefined>) {
  return useApi<Paginated<T>>(path, query);
}

/**
 * Write action with consistent feedback: the API's message is shown as a
 * success toast, failures show the API error (validation, permission, offline...).
 */
export function useAction<TVars, TResult = ApiResponse<unknown>>(
  fn: (vars: TVars) => Promise<TResult>,
  options: { invalidate?: QueryKey[]; onSuccess?: (result: TResult, vars: TVars) => void; onError?: (error: ApiError) => void; silent?: boolean } = {},
) {
  const queryClient = useQueryClient();
  const t = useT();

  return useMutation<TResult, ApiError, TVars>({
    mutationFn: fn,
    onSuccess: (result, vars) => {
      const message = (result as { message?: string } | undefined)?.message;
      if (message && !options.silent) toast.success(message);
      if (options.invalidate) {
        options.invalidate.forEach((key) => queryClient.invalidateQueries({ queryKey: key }));
      }
      options.onSuccess?.(result, vars);
    },
    onError: (error) => {
      // API messages already arrive in the user's language; local fallbacks are translated here.
      toast.error(error instanceof ApiError ? t(error.message) : t("Something went wrong. Please try again."));
      options.onError?.(error);
    },
  });
}

export function useDebounced<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(id);
  }, [value, delay]);
  return debounced;
}

/** Field-level validation errors from the last failed mutation. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) return {};
  return Object.fromEntries(Object.entries(error.errors).map(([k, v]) => [k, v[0]]));
}

/** Apply ?name=value from the URL once after mount (links from the dashboard). */
export function useUrlParam(name: string, apply: (value: string) => void) {
  useEffect(() => {
    const value = new URLSearchParams(window.location.search).get(name);
    if (value) apply(value);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}
