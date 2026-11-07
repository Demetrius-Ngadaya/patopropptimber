"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Download, Smartphone } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { cn } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";

/** The app version on offer, as GET public/app returns it (null until one is published). */
export type AppRelease = {
  id: number;
  platform: string;
  version: string;
  build_number: number;
  file_name: string;
  file_size: number | null;
  sha256: string | null;
  notes: string | null;
  is_required: boolean;
  released_at: string | null;
  download_url: string;
};

export function useAppRelease() {
  const q = useApi<ApiResponse<AppRelease | null>>("public/app");
  return { release: q.data?.data ?? null, isLoading: q.isLoading };
}

export function fileSize(bytes: number | null | undefined): string {
  if (!bytes) return "";
  return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** "android" | "ios" | "other", read after mount (the server cannot know). */
export function useDevice(): "android" | "ios" | "other" {
  const [device, setDevice] = useState<"android" | "ios" | "other">("other");
  useEffect(() => {
    const ua = navigator.userAgent;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the browser is only known after mount
    setDevice(/android/i.test(ua) ? "android" : /iphone|ipad|ipod/i.test(ua) ? "ios" : "other");
  }, []);
  return device;
}

/** Card under the login form: "Get the PatoPro app". Hidden until a version is published. */
export function AppDownloadCard({ className }: { className?: string }) {
  const t = useT();
  const { release } = useAppRelease();
  if (!release) return null;
  return (
    <Link
      href="/app"
      className={cn(
        "group flex items-center gap-3 rounded-xl border border-ink-200 bg-white/70 px-4 py-3 text-left transition-colors hover:border-brand-300 hover:bg-brand-50/60 dark:bg-ink-900/40",
        className,
      )}
    >
      <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-brand-600 text-white"><Smartphone className="size-5" /></span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-ink-900">{t("Download the PatoPro app")}</span>
        <span className="block text-xs text-ink-500">{t("Android · version {version} · works without internet", { version: release.version })}</span>
      </span>
      <Download className="size-5 shrink-0 text-brand-700 transition-transform group-hover:translate-y-0.5" />
    </Link>
  );
}

/** A small text link (shop footer, menus). */
export function AppDownloadLink({ className, before }: { className?: string; before?: string }) {
  const t = useT();
  const { release } = useAppRelease();
  if (!release) return null;
  return (
    <>
      {before}
      <Link href="/app" className={cn("inline-flex items-center gap-1 align-middle hover:underline", className)}>
        <Smartphone className="size-3.5" />
        {t("Download the app")}
      </Link>
    </>
  );
}
