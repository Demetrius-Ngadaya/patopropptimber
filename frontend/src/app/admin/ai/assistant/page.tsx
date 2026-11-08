"use client";

import { useEffect, useRef, useState } from "react";
import { Bot, MessageSquarePlus, Send, Sparkles, Trash2, UserRound, Wrench } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { api, ApiError, type ApiResponse, type Paginated } from "@/lib/api";
import { cn, dateTime } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, EmptyState, PageHeader, Panel, Spinner } from "@/components/ui";
import { Markdown } from "@/features/ai/components/Markdown";
import type { AiConversation, AiMessage, AiStatus } from "@/features/ai/types";

const SUGGESTIONS = [
  "How much did we sell this week compared with last week?",
  "Which products will run out in the next 7 days?",
  "Who owes us the most money and how overdue are they?",
  "What were our biggest expenses this month?",
  "Which cashier gave the most discounts this month?",
  "Andika muhtasari wa mauzo ya mwezi huu kwa Kiswahili.",
];

type Pending = { role: "user"; content: string };

export default function AssistantPage() {
  const t = useT();
  const qc = useQueryClient();
  const status = useApi<ApiResponse<AiStatus>>("admin/ai/status");
  const list = useApi<Paginated<AiConversation>>("admin/ai/conversations");
  const [active, setActive] = useState<number | null>(null);
  const convo = useApi<ApiResponse<AiConversation>>(active ? `admin/ai/conversations/${active}` : null);
  const [input, setInput] = useState("");
  const [pending, setPending] = useState<Pending | null>(null);
  const [error, setError] = useState<string | null>(null);
  const bottom = useRef<HTMLDivElement>(null);

  const messages: Array<AiMessage | Pending> = [...(active ? convo.data?.data.messages ?? [] : []), ...(pending ? [pending] : [])];
  // Block body on purpose: newer browsers return a Promise from scrollIntoView, and an effect may only return a cleanup function.
  useEffect(() => {
    bottom.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [messages.length]);

  const ask = useAction((message: string) => api.post<ApiResponse<{ conversation_id: number; title: string; message: AiMessage }>>("admin/ai/ask", { message, conversation_id: active }), {
    silent: true,
    onSuccess: async (res) => {
      // Load the saved thread before swapping it in, so the screen never flickers back to an older one.
      const path = `admin/ai/conversations/${res.data.conversation_id}`;
      await qc.fetchQuery({ queryKey: [path, {}], queryFn: () => api.get(path), staleTime: 0 });
      setActive(res.data.conversation_id);
      qc.invalidateQueries({ queryKey: ["admin/ai/conversations"] });
      qc.invalidateQueries({ queryKey: ["admin/ai/status"] });
      setPending(null);
    },
    onError: (e) => { setPending(null); setError(e instanceof ApiError ? e.message : t("The assistant could not answer. Try again.")); },
  });
  const remove = useAction((id: number) => api.del(`admin/ai/conversations/${id}`), { invalidate: [["admin/ai/conversations"]], onSuccess: () => setActive(null) });

  const send = (text = input) => {
    const message = text.trim();
    if (message.length < 2 || ask.isPending) return;
    setError(null);
    setPending({ role: "user", content: message });
    setInput("");
    ask.mutate(message);
  };

  const s = status.data?.data;
  const unavailable = s && (!s.enabled || !s.assistant_available);

  return (
    <Guard perm="ai.assistant">
      <PageHeader title={t("AI assistant")} description={t("Ask about sales, stock, debts, expenses and staff in plain English or Swahili. It reads live figures you are allowed to see; it cannot change anything.")} />
      {unavailable ? (
        <Panel><EmptyState icon={<Bot className="size-10" />} title={t("The AI assistant is not switched on")} message={!s.enabled ? t("An administrator can turn on AI features in Settings → AI.") : t("Choose a free AI provider in the backend .env: AI_PROVIDER=groq with GROQ_API_KEY (or gemini, openrouter, or a local ollama). Forecasts, anomaly alerts and credit scores work without it.")} /></Panel>
      ) : (
        <div className="grid gap-5 lg:grid-cols-[260px_1fr]">
          <Panel padded={false} className="hidden h-[calc(100vh-14rem)] flex-col overflow-hidden lg:flex">
            <div className="border-b border-ink-100 p-3"><Button className="w-full" variant="secondary" icon={<MessageSquarePlus className="size-4" />} onClick={() => { setActive(null); setError(null); }}>{t("New conversation")}</Button></div>
            <ul className="flex-1 overflow-y-auto p-2">
              {list.data?.data.map((c) => (
                <li key={c.id} className="group flex items-center">
                  <button type="button" onClick={() => setActive(c.id)} className={cn("min-w-0 flex-1 rounded-md px-2 py-2 text-left text-sm hover:bg-ink-50", active === c.id && "bg-brand-50 text-brand-800")}>
                    <span className="block truncate">{c.title}</span>
                    <span className="block text-xs text-ink-400">{dateTime(c.last_message_at)}</span>
                  </button>
                  <button type="button" aria-label={t("Delete")} onClick={() => confirm(t("Delete this conversation?")) && remove.mutate(c.id)} className="invisible rounded p-1 text-ink-400 hover:text-ember-600 group-hover:visible"><Trash2 className="size-4" /></button>
                </li>
              ))}
              {list.data && !list.data.data.length && <li className="px-2 py-4 text-sm text-ink-400">{t("No conversations yet.")}</li>}
            </ul>
          </Panel>

          <Panel padded={false} className="flex h-[calc(100vh-14rem)] min-h-[420px] flex-col overflow-hidden">
            <div className="flex-1 space-y-4 overflow-y-auto p-4" aria-live="polite">
              {active && convo.isLoading && <Spinner variant="compact" />}
              {!messages.length && !convo.isLoading && (
                <div className="mx-auto max-w-xl py-8 text-center">
                  <span className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-saffron-50 text-saffron-600"><Sparkles className="size-6" /></span>
                  <p className="mb-5 font-medium text-ink-800">{t("What would you like to know about the business?")}</p>
                  <div className="grid gap-2 sm:grid-cols-2">
                    {SUGGESTIONS.map((q) => <button key={q} type="button" onClick={() => send(q)} className="rounded-lg px-3 py-2 text-left text-sm text-ink-700 ring-1 ring-ink-200 hover:bg-ink-50">{t(q)}</button>)}
                  </div>
                </div>
              )}
              {messages.map((m, i) => (
                <div key={"id" in m ? m.id : `p${i}`} className={cn("flex gap-3", m.role === "user" && "flex-row-reverse")}>
                  <span className={cn("grid size-8 shrink-0 place-items-center rounded-full", m.role === "user" ? "bg-ink-100 text-ink-600" : "bg-brand-600 text-white")}>{m.role === "user" ? <UserRound className="size-4" /> : <Bot className="size-4" />}</span>
                  <div className={cn("max-w-[85%] rounded-2xl px-4 py-2.5", m.role === "user" ? "bg-brand-600 text-white keep-light" : "bg-ink-50 text-ink-900")}>
                    {m.role === "user" ? <p className="whitespace-pre-wrap text-sm">{m.content}</p> : <Markdown text={m.content} />}
                    {"tool_calls" in m && m.tool_calls?.length ? (
                      <p className="mt-2 flex flex-wrap items-center gap-1 text-[11px] text-ink-400"><Wrench className="size-3" />{t("Looked up")}: {Array.from(new Set(m.tool_calls.map((c) => String(c.tool ?? c.name ?? "").replace(/_/g, " ")).filter(Boolean))).join(", ")}</p>
                    ) : null}
                  </div>
                </div>
              ))}
              {ask.isPending && (
                <div className="flex gap-3"><span className="grid size-8 place-items-center rounded-full bg-brand-600 text-white"><Bot className="size-4" /></span><span className="rounded-2xl bg-ink-50 px-4 py-3 text-sm text-ink-500"><span className="inline-flex gap-1"><span className="size-1.5 animate-bounce rounded-full bg-ink-400" /><span className="size-1.5 animate-bounce rounded-full bg-ink-400 [animation-delay:120ms]" /><span className="size-1.5 animate-bounce rounded-full bg-ink-400 [animation-delay:240ms]" /></span> {t("Checking the figures…")}</span></div>
              )}
              {error && <p className="rounded-lg bg-ember-50 px-3 py-2 text-sm text-ember-700">{error}</p>}
              <div ref={bottom} />
            </div>
            <form className="flex items-end gap-2 border-t border-ink-100 p-3" onSubmit={(e) => { e.preventDefault(); send(); }}>
              <textarea value={input} onChange={(e) => setInput(e.target.value)} rows={1} maxLength={2000}
                onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); } }}
                placeholder={t("Ask a question… (Enter to send, Shift+Enter for a new line)")} aria-label={t("Message")}
                className="max-h-40 min-h-11 flex-1 resize-y rounded-[var(--radius-control)] border border-ink-200 bg-surface px-3 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20" />
              <Button type="submit" icon={<Send className="size-4" />} loading={ask.isPending} disabled={input.trim().length < 2}>{t("Send")}</Button>
            </form>
            {s && (
              <p className="flex justify-between gap-3 px-3 pb-2 text-[11px] text-ink-400">
                <span>{s.model && t("Model: {model}", { model: s.model })}</span>
                {s.budget.monthly_tokens > 0 && <span>{t("AI usage this month: {pct}% of budget", { pct: Math.min(100, Math.round((s.budget.used / s.budget.monthly_tokens) * 100)) })}</span>}
              </p>
            )}
          </Panel>
        </div>
      )}
    </Guard>
  );
}
