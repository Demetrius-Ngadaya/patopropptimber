"use client";

import { useMemo, useState } from "react";
import { ChevronDown, Search } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { cn } from "@/lib/format";
import { EmptyState, ErrorState, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Faq = { id: number; question: string; answer: string; category: string | null };

/** Questions and answers written by the business (Back office → Shop FAQs). */
export default function FaqPage() {
  const t = useT();
  const q = useApi<ApiResponse<Faq[]>>("shop/faqs");
  const [term, setTerm] = useState("");
  const [open, setOpen] = useState<number | null>(null);

  const groups = useMemo(() => {
    const words = term.trim().toLowerCase();
    const rows = (q.data?.data ?? []).filter((f) => !words || `${f.question} ${f.answer}`.toLowerCase().includes(words));
    const map = new Map<string, Faq[]>();
    for (const f of rows) {
      const key = f.category?.trim() || "";
      map.set(key, [...(map.get(key) ?? []), f]);
    }
    return [...map.entries()];
  }, [q.data, term]);

  return (
    <div className="mx-auto max-w-3xl">
      <h1 className="text-2xl font-semibold text-ink-950">{t("Questions & answers")}</h1>
      <p className="mt-1 text-ink-600">{t("Can't find your answer? Ask the chat assistant or call us.")}</p>
      <div className="relative mt-5">
        <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
        <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t("Search the questions")} aria-label={t("Search the questions")}
          className="h-11 w-full rounded-xl border border-ink-200 bg-surface pl-9 pr-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20" />
      </div>

      <div className="mt-6">
        {q.isLoading ? <Spinner variant="compact" /> : q.error ? <ErrorState message={q.error.message} onRetry={() => q.refetch()} /> : !groups.length ? (
          <EmptyState title={term ? t("No question matches your search") : t("No questions yet")} />
        ) : groups.map(([category, rows]) => (
          <section key={category || "general"} className="mb-6">
            {category && <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-500">{category}</h2>}
            <div className="divide-y divide-ink-100 overflow-hidden rounded-xl bg-surface ring-1 ring-ink-200/70">
              {rows.map((f) => {
                const isOpen = open === f.id;
                return (
                  <div key={f.id}>
                    <button type="button" aria-expanded={isOpen} onClick={() => setOpen(isOpen ? null : f.id)}
                      className="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left text-[15px] font-medium text-ink-900 hover:bg-ink-50">
                      {f.question}
                      <ChevronDown aria-hidden className={cn("size-4 shrink-0 text-ink-400 transition-transform", isOpen && "rotate-180")} />
                    </button>
                    {isOpen && <p className="whitespace-pre-line px-4 pb-4 text-sm leading-relaxed text-ink-700">{f.answer}</p>}
                  </div>
                );
              })}
            </div>
          </section>
        ))}
      </div>
    </div>
  );
}
