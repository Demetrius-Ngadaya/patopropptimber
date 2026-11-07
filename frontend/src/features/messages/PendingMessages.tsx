"use client";

import Link from "next/link";
import { useState } from "react";
import { CheckCircle2, Inbox, Megaphone } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { cn, dateTime } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Badge, Button } from "@/components/ui";
import { KIND_LABELS, KIND_TONES, type InboxRow } from "./types";

type Pending = { pending: InboxRow[]; unread: number };

/** Polls for messages from management: the unread count for the header, and what must be shown now. */
export function usePendingMessages(enabled: boolean) {
  return useApi<ApiResponse<Pending>>(enabled ? "admin/staff-messages/pending" : null);
}

/** Envelope in the top bar with the number of unread messages. */
export function InboxButton({ unread }: { unread: number }) {
  const t = useT();
  return (
    <Link href="/admin/inbox" className="relative rounded-md p-2 text-ink-600 hover:bg-ink-100" aria-label={unread ? t("Messages from management: {count} unread", { count: unread }) : t("Messages from management")}>
      <Inbox className="size-5" />
      {unread > 0 && <span className="num absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-ember-600 px-1 text-[10.5px] font-semibold text-white">{unread > 99 ? "99+" : unread}</span>}
    </Link>
  );
}

/**
 * Reminders and announcements that must be read: shown over everything right
 * after login, one after the other, until the person confirms each one.
 */
export function PendingMessagesDialog({ rows, onDone }: { rows: InboxRow[]; onDone: () => void }) {
  const t = useT();
  const [busy, setBusy] = useState(false);
  const [doneIds, setDoneIds] = useState<number[]>([]);
  const left = rows.filter((r) => !doneIds.includes(r.staff_message_id));
  const current = left[0];
  if (!current) return null;
  const m = current.message;
  const high = m.priority === "high";

  const confirm = async () => {
    setBusy(true);
    try {
      await api.post(`admin/staff-messages/${m.id}/acknowledge`);
      setDoneIds((d) => [...d, m.id]);
      if (left.length === 1) onDone();
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-night-950/70 p-4" role="alertdialog" aria-modal="true" aria-labelledby="pending-title">
      <div className={cn("w-full max-w-lg overflow-hidden rounded-2xl bg-surface shadow-2xl ring-4", high ? "ring-ember-500" : "ring-brand-500")}>
        <div className={cn("flex items-center gap-3 px-5 py-4 text-white", high ? "bg-ember-600" : m.kind === "announcement" ? "bg-night-800" : "bg-saffron-600")}>
          <Megaphone className="size-7 shrink-0" />
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider opacity-90">{t(KIND_LABELS[m.kind])}{high ? ` · ${t("Important")}` : ""}</p>
            <h2 id="pending-title" className="text-lg font-semibold leading-tight">{m.title}</h2>
          </div>
          {left.length > 1 && <span className="ml-auto shrink-0 rounded-full bg-white/20 px-2 py-0.5 text-xs">{t("{count} to read", { count: left.length })}</span>}
        </div>
        <div className="max-h-[50dvh] overflow-y-auto px-5 py-4">
          <p className="whitespace-pre-line text-[15px] leading-relaxed text-ink-900">{m.body}</p>
          <p className="mt-4 text-xs text-ink-500">{m.sender?.name} · {dateTime(m.created_at)}</p>
        </div>
        <div className="flex justify-end border-t border-ink-100 px-5 py-3">
          <Button size="lg" icon={<CheckCircle2 className="size-5" />} loading={busy} onClick={confirm} data-autofocus>{t("I have read it")}</Button>
        </div>
      </div>
    </div>
  );
}

export function KindBadge({ kind }: { kind: InboxRow["message"]["kind"] }) {
  const t = useT();
  return <Badge tone={KIND_TONES[kind]}>{t(KIND_LABELS[kind])}</Badge>;
}
