"use client";

import Link from "next/link";
import { useState } from "react";
import { CheckCheck } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { cn, dateTime } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { Button, EmptyState, PageHeader, Pagination, Panel, Select, Spinner, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { useNotificationActions, type AppNotification } from "@/features/notifications/api";
import { NotificationIcon } from "@/features/notifications/NotificationIcon";

const TYPES = ["low_stock", "out_of_stock", "stock_digest", "sales_report", "debt_overdue", "new_order", "sale_cancelled", "leave_request", "payroll_prepared"];
const TYPE_LABELS: Record<string, string> = {
  low_stock: "Low stock", out_of_stock: "Out of stock", stock_digest: "Stock check", sales_report: "Sales reports", debt_overdue: "Overdue debts",
  new_order: "Online orders", sale_cancelled: "Cancelled sales", leave_request: "Leave requests", payroll_prepared: "Payroll",
};

/** Every notification the user may see (their role and branch decide which). */
export default function NotificationsPage() {
  const t = useT();
  const [tab, setTab] = useState<"all" | "unread">("all");
  const [type, setType] = useState("");
  const [page, setPage] = useState(1);
  const list = useApi<Paginated<AppNotification>>("admin/notifications", { unread: tab === "unread" ? 1 : undefined, type: type || undefined, page });
  const { read, readAll } = useNotificationActions();
  const rows = list.data?.data ?? [];

  return (
    <>
      <PageHeader
        title={t("Notifications")}
        description={t("Alerts and reports for your role and branch.")}
        actions={<Button variant="secondary" icon={<CheckCheck className="size-4" />} onClick={() => readAll()}>{t("Mark all as read")}</Button>}
      />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Tabs value={tab} onChange={(v) => { setTab(v); setPage(1); }} tabs={[{ value: "all", label: t("All") }, { value: "unread", label: t("Unread") }]} />
        <Select aria-label={t("Type")} className="w-48" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
          <option value="">{t("All types")}</option>
          {TYPES.map((ty) => <option key={ty} value={ty}>{t(TYPE_LABELS[ty])}</option>)}
        </Select>
      </div>
      <Panel padded={false}>
        {list.isLoading ? <Spinner /> : rows.length === 0 ? (
          <EmptyState title={tab === "unread" ? t("You're all caught up.") : t("No notifications yet")} />
        ) : (
          <ul className="divide-y divide-ink-100">
            {rows.map((n) => {
              const inner = (
                <>
                  <NotificationIcon n={n} />
                  <span className="min-w-0 flex-1">
                    <span className={cn("block text-sm text-ink-900", !n.read && "font-semibold")}>{n.title}</span>
                    {n.body && <span className="mt-0.5 block text-sm text-ink-600">{n.body}</span>}
                    <span className="mt-1 block text-xs text-ink-500">{dateTime(n.created_at)}</span>
                  </span>
                  {!n.read && <span className="mt-2 size-2 shrink-0 rounded-full bg-brand-600" aria-label={t("Unread")} />}
                </>
              );
              const cls = cn("flex gap-3 px-4 py-3.5", !n.read && "bg-brand-50/40", n.url && "hover:bg-ink-50");
              return (
                <li key={n.id}>
                  {n.url
                    ? <Link href={n.url} onClick={() => !n.read && read(n.id)} className={cls}>{inner}</Link>
                    : <button type="button" onClick={() => !n.read && read(n.id)} className={cn(cls, "w-full text-left")}>{inner}</button>}
                </li>
              );
            })}
          </ul>
        )}
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </>
  );
}
