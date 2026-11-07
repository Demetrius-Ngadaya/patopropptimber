"use client";

import { useState } from "react";
import { CheckCircle2, Inbox } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { cn, dateTime } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Badge, Button, EmptyState, PageHeader, Pagination, Panel, Spinner, Tabs } from "@/components/ui";
import { KindBadge } from "@/features/messages/PendingMessages";
import type { InboxRow } from "@/features/messages/types";

/** Messages, reminders and announcements from management to me. */
export default function InboxPage() {
  const t = useT();
  const [filter, setFilter] = useState<"" | "unread">("");
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const list = useApi<Paginated<InboxRow> & { unread: number }>("admin/staff-messages", { filter, page });
  const read = useAction((id: number) => api.post(`admin/staff-messages/${id}/read`), { invalidate: [["admin/staff-messages"], ["admin/staff-messages/pending"]], silent: true });
  const ack = useAction((id: number) => api.post(`admin/staff-messages/${id}/acknowledge`), { invalidate: [["admin/staff-messages"], ["admin/staff-messages/pending"]] });

  const toggle = (row: InboxRow) => {
    setOpen(open === row.id ? null : row.id);
    if (!row.read_at) read.mutate(row.staff_message_id);
  };

  return (
    <>
      <PageHeader title={t("Messages from management")} description={t("Messages, reminders and announcements sent to you. Important ones also appear when you log in until you confirm them.")} />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs value={filter} onChange={(v) => { setFilter(v); setPage(1); }} tabs={[{ value: "", label: t("All") }, { value: "unread", label: t("Unread"), count: list.data?.unread }]} />
        </div>
        {list.isLoading && <Spinner />}
        {!list.isLoading && !list.data?.data.length && <EmptyState icon={<Inbox className="size-9" />} title={t("No messages")} />}
        <ul className="divide-y divide-ink-100">
          {list.data?.data.map((row) => {
            const m = row.message;
            const expanded = open === row.id;
            return (
              <li key={row.id} className={cn(!row.read_at && "bg-brand-50/40")}>
                <button type="button" onClick={() => toggle(row)} aria-expanded={expanded} className="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-ink-50/70">
                  <span className={cn("mt-1.5 size-2 shrink-0 rounded-full", row.read_at ? "bg-transparent" : "bg-brand-600")} aria-hidden />
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                      <span className={cn("text-sm", row.read_at ? "text-ink-800" : "font-semibold text-ink-950")}>{m.title}</span>
                      <KindBadge kind={m.kind} />
                      {m.priority === "high" && <Badge tone="red">{t("Important")}</Badge>}
                    </span>
                    {!expanded && <span className="mt-0.5 block truncate text-sm text-ink-500">{m.body}</span>}
                    <span className="mt-0.5 block text-xs text-ink-500">{m.sender?.name} · {dateTime(m.created_at)}</span>
                  </span>
                </button>
                {expanded && (
                  <div className="px-9 pb-4">
                    <p className="whitespace-pre-line text-[15px] text-ink-900">{m.body}</p>
                    {m.requires_ack && (row.acknowledged_at
                      ? <p className="mt-3 flex items-center gap-1.5 text-xs text-brand-700"><CheckCircle2 className="size-4" />{t("You confirmed on {date}", { date: dateTime(row.acknowledged_at) })}</p>
                      : <Button size="sm" className="mt-3" icon={<CheckCircle2 className="size-4" />} loading={ack.isPending} onClick={() => ack.mutate(m.id)}>{t("I have read it")}</Button>)}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </>
  );
}
