"use client";

import Link from "next/link";
import { Fragment, useEffect, useRef, useState } from "react";
import { MessageCircle, MessageSquareText, Phone, RotateCcw, Send, Sparkles, X } from "lucide-react";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";

/** "0754 100 001" or "+255 754..." → "255754100001" (for wa.me and tel:). */
function international(raw?: string | null): string {
  const digits = (raw ?? "").replace(/\D+/g, "");
  if (!digits) return "";
  if (digits.startsWith("0") && digits.length === 10) return `255${digits.slice(1)}`;
  if (digits.length === 9) return `255${digits}`;
  return digits;
}

/* ------------------------------------------------------------------ contact (left) */

/** Floating contact button: call, SMS or WhatsApp the business, numbers from Settings → Online shop. */
export function ContactWidget() {
  const t = useT();
  const { settings } = usePublicSettings();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent | KeyboardEvent) => {
      if (e instanceof KeyboardEvent ? e.key === "Escape" : !ref.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", close);
    return () => { document.removeEventListener("mousedown", close); document.removeEventListener("keydown", close); };
  }, [open]);

  if (!settings || settings.shop_contact_widget === false) return null;
  const call = international(settings.shop_contact_phone || settings.business_phone);
  const sms = international(settings.shop_contact_sms || settings.shop_contact_phone || settings.business_phone);
  const whatsapp = international(settings.shop_contact_whatsapp || settings.business_whatsapp);
  if (!call && !sms && !whatsapp) return null;
  const message = encodeURIComponent(settings.shop_contact_message || t("Hello, I have a question about your products."));

  const options = [
    call && { key: "call", href: `tel:+${call}`, label: t("Call us"), sub: `+${call}`, icon: <Phone className="size-5" />, tone: "bg-brand-600" },
    sms && { key: "sms", href: `sms:+${sms}?body=${message}`, label: t("Send an SMS"), sub: `+${sms}`, icon: <MessageSquareText className="size-5" />, tone: "bg-sky-600" },
    whatsapp && { key: "wa", href: `https://wa.me/${whatsapp}?text=${message}`, label: "WhatsApp", sub: `+${whatsapp}`, icon: <WhatsAppIcon />, tone: "bg-[#25D366]" },
  ].filter(Boolean) as Array<{ key: string; href: string; label: string; sub: string; icon: React.ReactNode; tone: string }>;

  return (
    <div ref={ref} className="no-print fixed bottom-5 left-4 z-40 sm:left-6">
      {open && (
        <div role="menu" aria-label={t("Contact us")} className="widget-pop mb-3 w-64 overflow-hidden rounded-2xl bg-surface shadow-2xl ring-1 ring-ink-200">
          <p className="border-b border-ink-100 px-4 py-3 text-sm font-semibold text-ink-900">{t("How would you like to reach us?")}</p>
          {options.map((o) => (
            <a key={o.key} role="menuitem" href={o.href} target={o.key === "wa" ? "_blank" : undefined} rel={o.key === "wa" ? "noreferrer" : undefined}
              onClick={() => setOpen(false)} className="flex items-center gap-3 px-4 py-3 hover:bg-ink-50 focus:bg-ink-50 focus:outline-none">
              <span className={cn("grid size-10 shrink-0 place-items-center rounded-full text-white", o.tone)}>{o.icon}</span>
              <span className="min-w-0">
                <span className="block text-sm font-medium text-ink-900">{o.label}</span>
                <span className="num block text-xs text-ink-500">{o.sub}</span>
              </span>
            </a>
          ))}
        </div>
      )}
      <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open} aria-haspopup="menu" aria-label={open ? t("Close") : t("Contact us")}
        className="widget-float relative grid size-14 place-items-center rounded-full bg-brand-600 text-white shadow-lg shadow-brand-900/25 transition hover:scale-105 hover:bg-brand-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/40">
        {!open && <span aria-hidden className="absolute inset-0 animate-ping rounded-full bg-brand-500/40 motion-reduce:hidden" />}
        {open ? <X className="relative size-6" /> : <Phone className="widget-ring relative size-6" />}
      </button>
    </div>
  );
}

function WhatsAppIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden className="size-5" fill="currentColor">
      <path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z" />
    </svg>
  );
}

/* ------------------------------------------------------------------ assistant (right) */

type Message = { role: "user" | "assistant"; content: string; error?: boolean };
type Faq = { id: number; question: string; answer: string };
const STORE = "pp_chat";

function loadChat(): Message[] {
  try {
    const raw = window.sessionStorage.getItem(STORE);
    const list = raw ? (JSON.parse(raw) as Message[]) : [];
    return Array.isArray(list) ? list.filter((m) => (m.role === "user" || m.role === "assistant") && typeof m.content === "string").slice(-30) : [];
  } catch {
    return [];
  }
}

/** Floating AI chat: answers from the shop's products, branches, offers and FAQ, and general questions. */
export function AssistantWidget() {
  const t = useT();
  const { settings } = usePublicSettings();
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const listRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const faqs = useApi<ApiResponse<Faq[]>>(open ? "shop/faqs" : null);

  useEffect(() => { setMessages(loadChat()); }, []);
  useEffect(() => {
    try { window.sessionStorage.setItem(STORE, JSON.stringify(messages.filter((m) => !m.error).slice(-30))); } catch { /* storage unavailable */ }
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: "smooth" });
  }, [messages, busy]);
  useEffect(() => { if (open) setTimeout(() => inputRef.current?.focus(), 50); }, [open]);

  if (!settings || settings.shop_assistant_enabled === false) return null;
  const business = settings.business_name || "";

  const ask = async (question: string) => {
    const q = question.trim();
    if (!q || busy) return;
    const history = messages.filter((m) => !m.error).slice(-10).map(({ role, content }) => ({ role, content }));
    setMessages((m) => [...m, { role: "user", content: q }]);
    setText("");
    setBusy(true);
    try {
      const res = await api.post<ApiResponse<{ answer: string }>>("shop/assistant", { message: q, history });
      setMessages((m) => [...m, { role: "assistant", content: res.data.answer }]);
    } catch (e) {
      setMessages((m) => [...m, { role: "assistant", content: e instanceof ApiError && e.message ? t(e.message) : t("Sorry, I could not answer just now. Please try again."), error: true }]);
    } finally {
      setBusy(false);
    }
  };

  const suggestions = [
    ...(faqs.data?.data ?? []).slice(0, 3).map((f) => f.question),
    t("What products do you sell?"),
    t("Do you deliver? How much?"),
  ].slice(0, 4);

  return (
    <div className="no-print fixed bottom-5 right-4 z-40 sm:right-6">
      {open && (
        <section role="dialog" aria-label={t("Chat assistant")}
          className="widget-pop fixed inset-x-3 bottom-24 top-20 flex flex-col overflow-hidden rounded-2xl bg-surface shadow-2xl ring-1 ring-ink-200 sm:absolute sm:inset-auto sm:bottom-20 sm:right-0 sm:h-[560px] sm:max-h-[calc(100dvh-8rem)] sm:w-[380px]">
          <header className="flex items-center gap-3 bg-night-900 px-4 py-3 text-white">
            <span className="grid size-9 place-items-center rounded-full bg-white/10"><Sparkles className="size-5 text-saffron-300" /></span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold">{t("Shop assistant")}</p>
              <p className="truncate text-xs text-white/70">{business ? t("Ask anything about {business}", { business }) : t("Ask me anything")}</p>
            </div>
            {messages.length > 0 && <button type="button" onClick={() => setMessages([])} className="rounded-md p-1.5 text-white/80 hover:bg-white/10" aria-label={t("New chat")} title={t("New chat")}><RotateCcw className="size-4" /></button>}
            <button type="button" onClick={() => setOpen(false)} className="rounded-md p-1.5 hover:bg-white/10" aria-label={t("Close")}><X className="size-5" /></button>
          </header>

          <div ref={listRef} className="flex-1 space-y-3 overflow-y-auto bg-ink-50/60 px-3 py-4" aria-live="polite">
            <Bubble role="assistant">{t("Hello! I can help you find products and prices, delivery, our branches and offers, or answer any other question. What would you like to know?")}</Bubble>
            {messages.map((m, i) => <Bubble key={i} role={m.role} error={m.error}>{m.role === "assistant" ? <RichText text={m.content} onNavigate={() => setOpen(false)} /> : m.content}</Bubble>)}
            {busy && (
              <div className="flex gap-1.5 px-2 py-1" role="status" aria-label={t("Typing")}>
                {[0, 1, 2].map((d) => <span key={d} className="size-2 animate-bounce rounded-full bg-ink-400" style={{ animationDelay: `${d * 150}ms` }} />)}
              </div>
            )}
            {!messages.length && !busy && (
              <div className="flex flex-wrap gap-2 pt-1">
                {suggestions.map((s) => (
                  <button key={s} type="button" onClick={() => void ask(s)} className="rounded-full bg-surface px-3 py-1.5 text-left text-xs font-medium text-brand-700 ring-1 ring-brand-200 hover:bg-brand-50">{s}</button>
                ))}
              </div>
            )}
          </div>

          <form onSubmit={(e) => { e.preventDefault(); void ask(text); }} className="flex items-end gap-2 border-t border-ink-200 bg-surface p-3">
            <textarea ref={inputRef} rows={1} value={text} maxLength={1000} onChange={(e) => setText(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); void ask(text); } }}
              placeholder={t("Type your question…")} aria-label={t("Your question")}
              className="max-h-28 min-h-10 flex-1 resize-none rounded-xl border border-ink-200 bg-surface px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20" />
            <button type="submit" disabled={!text.trim() || busy} aria-label={t("Send")} className="grid size-10 shrink-0 place-items-center rounded-full bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-40">
              <Send className="size-4" />
            </button>
          </form>
          <p className="bg-surface px-3 pb-2 text-center text-[11px] text-ink-400">
            <Link href="/faq" onClick={() => setOpen(false)} className="hover:underline">{t("Questions & answers")}</Link> · {t("Answers may not always be perfect.")}
          </p>
        </section>
      )}
      <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open} aria-label={open ? t("Close") : t("Chat with us")}
        className="widget-float widget-float-late relative grid size-14 place-items-center rounded-full bg-night-900 text-white shadow-lg shadow-night-950/30 transition hover:scale-105 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/40">
        {open ? <X className="size-6" /> : <MessageCircle className="size-6" />}
        {!open && <span aria-hidden className="absolute -right-0.5 -top-0.5 grid size-5 place-items-center rounded-full bg-saffron-300 text-night-950 ring-2 ring-surface"><Sparkles className="size-3" /></span>}
      </button>
    </div>
  );
}

function Bubble({ role, error, children }: { role: Message["role"]; error?: boolean; children: React.ReactNode }) {
  return (
    <div className={cn("flex", role === "user" ? "justify-end" : "justify-start")}>
      <div className={cn("max-w-[85%] whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed",
        role === "user" ? "rounded-br-md bg-brand-600 text-white" : error ? "rounded-bl-md bg-ember-50 text-ember-800 ring-1 ring-ember-100" : "rounded-bl-md bg-surface text-ink-900 ring-1 ring-ink-200/70")}>
        {children}
      </div>
    </div>
  );
}

/** Light formatting for answers: **bold**, [links](/products/...), "- " bullets. Never raw HTML. */
function RichText({ text, onNavigate }: { text: string; onNavigate: () => void }) {
  const inline = (line: string, key: string) => {
    const parts: React.ReactNode[] = [];
    const re = /\*\*([^*]+)\*\*|\[([^\]]+)\]\(((?:\/|https?:\/\/)[^)\s]+)\)/g;
    let last = 0;
    let m: RegExpExecArray | null;
    let i = 0;
    while ((m = re.exec(line))) {
      if (m.index > last) parts.push(line.slice(last, m.index));
      if (m[1]) parts.push(<strong key={`${key}b${i++}`}>{m[1]}</strong>);
      else if (m[3].startsWith("/")) parts.push(<Link key={`${key}l${i++}`} href={m[3]} onClick={onNavigate} className="font-medium text-brand-700 underline underline-offset-2">{m[2]}</Link>);
      else parts.push(<a key={`${key}a${i++}`} href={m[3]} target="_blank" rel="noreferrer nofollow" className="font-medium text-brand-700 underline underline-offset-2">{m[2]}</a>);
      last = m.index + m[0].length;
    }
    if (last < line.length) parts.push(line.slice(last));
    return parts;
  };
  const lines = text.replace(/\r/g, "").split("\n");
  return (
    <>
      {lines.map((line, n) => {
        const bullet = /^\s*[-*•]\s+/.test(line);
        const body = line.replace(/^\s*[-*•]\s+/, "").replace(/^#+\s*/, "");
        return (
          <Fragment key={n}>
            {bullet ? <span className="flex gap-1.5"><span aria-hidden>•</span><span>{inline(body, `${n}`)}</span></span> : inline(body, `${n}`)}
            {n < lines.length - 1 && !bullet && "\n"}
          </Fragment>
        );
      })}
    </>
  );
}
