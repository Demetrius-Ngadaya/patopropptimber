"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { api, type ApiResponse } from "@/lib/api";

export type AppNotification = {
  id: number;
  type: string;
  level: "info" | "success" | "warning" | "danger";
  title: string;
  body: string | null;
  url: string | null;
  read: boolean;
  created_at: string;
};

export const SUMMARY_KEY = ["admin/notifications/summary"];

/** Unread count + latest notifications. Polls every minute and when the tab gets focus. */
export function useNotificationSummary(enabled = true) {
  return useQuery({
    queryKey: SUMMARY_KEY,
    queryFn: ({ signal }) => api.get<ApiResponse<{ unread: number; latest: AppNotification[] }>>("admin/notifications/summary", undefined, signal),
    enabled,
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: true,
    staleTime: 20_000,
  });
}

export function useNotificationActions() {
  const qc = useQueryClient();
  const refresh = () => {
    qc.invalidateQueries({ queryKey: SUMMARY_KEY });
    qc.invalidateQueries({ queryKey: ["admin/notifications"] });
  };
  return {
    read: (id: number) => api.post(`admin/notifications/${id}/read`).then(refresh).catch(() => undefined),
    readAll: () => api.post("admin/notifications/read-all").then(refresh),
  };
}

/** "5 min ago", "yesterday", or a date. */
export function ago(iso: string, t: (k: string, p?: Record<string, string | number>) => string): string {
  const then = new Date(iso).getTime();
  const mins = Math.max(0, Math.round((Date.now() - then) / 60_000));
  if (mins < 1) return t("just now");
  if (mins < 60) return t("{n} min ago", { n: mins });
  const hours = Math.round(mins / 60);
  if (hours < 24) return t("{n} h ago", { n: hours });
  const days = Math.round(hours / 24);
  if (days === 1) return t("yesterday");
  if (days < 7) return t("{n} days ago", { n: days });
  return new Date(iso).toLocaleDateString(undefined, { day: "2-digit", month: "short" });
}
