"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { BellRing, Plus } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { dateTime } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, PageHeader, Panel } from "@/components/ui";
import { CHANNELS } from "@/features/messaging/channels";
import { PERIOD_LABELS, WEEKDAY_LABELS, type NotificationRule, type Source } from "@/features/notification-rules/types";

export default function NotificationRulesPage() {
  const t = useT();
  const router = useRouter();
  const rules = useApi<ApiResponse<NotificationRule[]>>("admin/notification-rules");
  const sources = useApi<ApiResponse<Source[]>>("admin/notification-rules/sources");
  const sourceName = (key: string) => sources.data?.data.find((s) => s.key === key)?.label ?? key;

  const when = (r: NotificationRule) => {
    if (r.frequency === "once") return t("Once, {date}", { date: dateTime(r.run_at) });
    if (r.frequency === "hourly") return t("Every hour at minute {minute}", { minute: r.time.slice(3, 5) });
    if (r.frequency === "weekly") return t("Every {day} at {time}", { day: t(WEEKDAY_LABELS[r.weekday ?? 1]), time: r.time.slice(0, 5) });
    if (r.frequency === "monthly") return r.month_day === 0 ? t("Last day of the month at {time}", { time: r.time.slice(0, 5) }) : t("Day {day} of the month at {time}", { day: r.month_day ?? 1, time: r.time.slice(0, 5) });
    return t("Every day at {time}", { time: r.time.slice(0, 5) });
  };

  return (
    <Guard perm="settings.notifications">
      <PageHeader
        title={t("Automatic notifications")}
        description={t("Reports and alerts the system sends by itself: what to report, which roles and numbers receive it, by SMS, WhatsApp or email, and when. Everyone in the chosen roles also sees it in the bell.")}
        actions={<Link href="/admin/notification-rules/new"><Button icon={<Plus className="size-4" />}>{t("New notification")}</Button></Link>}
      />
      <Panel padded={false}>
        <DataTable
          rows={rules.data?.data}
          loading={rules.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/notification-rules/${r.id}`)}
          columns={[
            { key: "name", header: t("Notification"), render: (r) => <span className="font-medium text-ink-900">{r.name}<span className="block text-xs font-normal text-ink-500">{sourceName(r.source)} · {t(PERIOD_LABELS[r.period] ?? r.period)}</span></span> },
            { key: "when", header: t("When"), render: (r) => when(r) },
            { key: "channels", header: t("Sent by"), hideOnMobile: true, render: (r) => r.channels.length ? r.channels.map((c) => CHANNELS.find((x) => x.value === c)?.label ?? c).join(", ") : <span className="text-ink-500">{t("Bell only")}</span> },
            { key: "next_run_at", header: t("Next"), hideOnMobile: true, render: (r) => r.is_active && r.next_run_at ? dateTime(r.next_run_at) : "—" },
            { key: "last_run_at", header: t("Last sent"), hideOnMobile: true, render: (r) => r.last_run_at ? dateTime(r.last_run_at) : t("Never") },
            { key: "is_active", header: t("State"), render: (r) => <Badge tone={r.is_active ? "green" : "neutral"}>{r.is_active ? t("On") : t("Off")}</Badge> },
          ]}
          empty={{
            title: t("No automatic notifications yet"),
            message: t("For example: a sales summary to the owner every evening, low stock to the storekeeper every morning, or overdue debts to the accountant every Monday."),
            action: <Link href="/admin/notification-rules/new"><Button icon={<BellRing className="size-4" />}>{t("Create the first one")}</Button></Link>,
          }}
        />
      </Panel>
    </Guard>
  );
}
