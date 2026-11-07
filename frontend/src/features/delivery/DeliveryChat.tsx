"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { CheckCheck, Send } from "lucide-react";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { cn, formatDate } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Button } from "@/components/ui";
import type { ChatMessage } from "./types";

/**
 * Two-way chat between the delivery person and the customer. New messages are
 * fetched every few seconds (only those after the last one we have).
 */
export function DeliveryChat({ path, me, disabled, className }: { path: string; me: "staff" | "customer"; disabled?: boolean; className?: string }) {
  const t = useT();
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [text, setText] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const lastId = useRef(0);
  const list = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    try {
      const r = await api.get<ApiResponse<ChatMessage[]>>(path, { after: lastId.current });
      if (r.data.length) {
        lastId.current = r.data[r.data.length - 1].id;
        setMessages((m) => [...m.filter((x) => !r.data.some((y) => y.id === x.id)), ...r.data]);
      }
    } catch {
      /* offline for a moment: the next poll catches up */
    }
  }, [path]);

  useEffect(() => {
    load();
    const id = setInterval(() => { if (document.visibilityState === "visible") load(); }, 5000);
    return () => clearInterval(id);
  }, [load]);

  useEffect(() => {
    list.current?.scrollTo({ top: list.current.scrollHeight, behavior: "smooth" });
  }, [messages.length]);

  const send = async () => {
    const body = text.trim();
    if (!body) return;
    setSending(true);
    setError(null);
    try {
      await api.post(path, { body });
      setText("");
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t("Not sent. Check your connection."));
    } finally {
      setSending(false);
    }
  };

  return (
    <div className={cn("flex flex-col", className)}>
      <div ref={list} className="min-h-40 flex-1 space-y-2 overflow-y-auto rounded-lg bg-ink-50 p-3" aria-live="polite">
        {!messages.length && <p className="py-6 text-center text-sm text-ink-500">{me === "staff" ? t("No messages yet. Write to the customer if you need directions.") : t("No messages yet. You can write to the delivery person here.")}</p>}
        {messages.map((m) => {
          const mine = m.sender === me;
          return (
            <div key={m.id} className={cn("flex", mine ? "justify-end" : "justify-start")}>
              <div className={cn("max-w-[80%] rounded-2xl px-3 py-1.5 text-sm shadow-sm", mine ? "rounded-br-sm bg-brand-600 text-white" : "rounded-bl-sm bg-surface text-ink-900 ring-1 ring-ink-200")}>
                {!mine && m.name && <p className="text-[11px] font-semibold text-brand-700">{m.name}</p>}
                <p className="whitespace-pre-line break-words">{m.body}</p>
                <p className={cn("mt-0.5 flex items-center justify-end gap-1 text-[10.5px]", mine ? "text-white/75" : "text-ink-400")}>
                  {formatDate(m.created_at, { hour: "2-digit", minute: "2-digit" })}
                  {mine && m.read && <CheckCheck className="size-3" aria-label={t("Read")} />}
                </p>
              </div>
            </div>
          );
        })}
      </div>
      {!disabled && (
        <form className="mt-2 flex gap-2" onSubmit={(e) => { e.preventDefault(); send(); }}>
          <input value={text} onChange={(e) => setText(e.target.value)} maxLength={1000} placeholder={t("Write a message")} aria-label={t("Write a message")}
            className="h-10 min-w-0 flex-1 rounded-full border border-ink-200 bg-surface px-4 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20" />
          <Button type="submit" loading={sending} disabled={!text.trim()} aria-label={t("Send")} className="rounded-full"><Send className="size-4" /></Button>
        </form>
      )}
      {error && <p className="mt-1 text-xs text-ember-600">{error}</p>}
    </div>
  );
}
