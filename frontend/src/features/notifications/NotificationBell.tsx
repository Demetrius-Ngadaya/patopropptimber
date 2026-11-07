"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Bell, CheckCheck } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { ago, useNotificationActions, useNotificationSummary, type AppNotification } from "./api";
import { NotificationIcon } from "./NotificationIcon";

/** The bell in the top bar: unread count, the latest few, and a link to all. */
export function NotificationBell() {
  const t = useT();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const summary = useNotificationSummary();
  const { read, readAll } = useNotificationActions();
  const unread = summary.data?.data.unread ?? 0;
  const latest = summary.data?.data.latest ?? [];

  const openOne = (n: AppNotification) => {
    if (!n.read) read(n.id);
    setOpen(false);
    if (n.url) router.push(n.url);
  };

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-label={unread ? t("Notifications, {count} unread", { count: unread }) : t("Notifications")}
        className="relative rounded-md p-2 text-ink-700 hover:bg-ink-100"
      >
        <Bell className="size-5" aria-hidden />
        {unread > 0 && (
          <span className="num absolute -right-0.5 -top-0.5 grid h-[18px] min-w-[18px] place-items-center rounded-full bg-ember-600 px-1 text-[10.5px] font-bold leading-none text-white ring-2 ring-surface">
            {unread > 99 ? "99+" : unread}
          </span>
        )}
      </button>
      {open && (
        <>
          <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} aria-hidden />
          <div className="absolute right-0 z-40 mt-1 w-[min(380px,calc(100vw-24px))] overflow-hidden rounded-lg bg-surface shadow-xl ring-1 ring-ink-200">
            <div className="flex items-center justify-between border-b border-ink-100 px-4 py-2.5">
              <p className="text-sm font-semibold text-ink-900">{t("Notifications")}</p>
              {unread > 0 && (
                <button type="button" onClick={() => readAll()} className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline">
                  <CheckCheck className="size-3.5" /> {t("Mark all as read")}
                </button>
              )}
            </div>
            <ul className="max-h-[60vh] divide-y divide-ink-100 overflow-y-auto">
              {latest.length === 0 && <li className="px-4 py-8 text-center text-sm text-ink-500">{t("You're all caught up.")}</li>}
              {latest.map((n) => (
                <li key={n.id}>
                  <button type="button" onClick={() => openOne(n)} className={cn("flex w-full gap-3 px-4 py-3 text-left hover:bg-ink-50", !n.read && "bg-brand-50/40")}>
                    <NotificationIcon n={n} />
                    <span className="min-w-0 flex-1">
                      <span className={cn("block text-[13.5px] leading-snug text-ink-900", !n.read && "font-semibold")}>{n.title}</span>
                      {n.body && <span className="mt-0.5 line-clamp-2 block text-xs text-ink-600">{n.body}</span>}
                      <span className="mt-1 block text-[11px] text-ink-500">{ago(n.created_at, t)}</span>
                    </span>
                    {!n.read && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand-600" aria-label={t("Unread")} />}
                  </button>
                </li>
              ))}
            </ul>
            <Link href="/admin/notifications" onClick={() => setOpen(false)} className="block border-t border-ink-100 px-4 py-2.5 text-center text-sm font-medium text-brand-700 hover:bg-ink-50">
              {t("See all notifications")}
            </Link>
          </div>
        </>
      )}
    </div>
  );
}
