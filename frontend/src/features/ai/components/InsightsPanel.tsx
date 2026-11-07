"use client";

import Link from "next/link";
import { AlertTriangle, ArrowRight, Info, Sparkles, TrendingUp } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Panel, Spinner } from "@/components/ui";
import type { InsightReport } from "../types";
import { Markdown } from "./Markdown";

const ICON = { positive: TrendingUp, warning: AlertTriangle, critical: AlertTriangle, info: Info };
const TONE = { positive: "text-brand-600 bg-brand-50", warning: "text-saffron-700 bg-saffron-50", critical: "text-ember-600 bg-ember-50", info: "text-sky-700 bg-sky-50" };

/** Dashboard card: rule-based findings for the week, plus an AI-written summary when a language model is configured. */
export function InsightsPanel() {
  const t = useT();
  const { can } = useAuth();
  const q = useApi<ApiResponse<InsightReport>>(can("ai.insights") ? "admin/ai/insights" : null, undefined, { keepPrevious: true });
  if (!can("ai.insights")) return null;
  const r = q.data?.data;
  return (
    <Panel title={<span className="flex items-center gap-2"><Sparkles className="size-4 text-saffron-500" />{t("Smart insights")}</span>}>
      {!r ? <Spinner variant="compact" /> : (
        <div className="space-y-3">
          {r.narrative && <div className="rounded-lg bg-ink-50 px-3 py-2 text-ink-800"><Markdown text={r.narrative} /></div>}
          {!r.insights.length && <p className="text-sm text-ink-500">{t("Nothing unusual this week.")}</p>}
          <ul className="space-y-2">
            {r.insights.map((i) => {
              const Icon = ICON[i.type] ?? Info;
              return (
                <li key={i.key}>
                  <Link href={`/admin${i.link}`} className="group flex gap-3 rounded-lg p-2 hover:bg-ink-50">
                    <span className={cn("grid size-8 shrink-0 place-items-center rounded-full", TONE[i.type])}><Icon className="size-4" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-medium text-ink-900">{i.title}</span>
                      <span className="block text-xs text-ink-500">{i.detail}</span>
                    </span>
                    <ArrowRight className="mt-2 size-4 shrink-0 text-ink-300 group-hover:text-ink-600" />
                  </Link>
                </li>
              );
            })}
          </ul>
          {!r.ai_available && <p className="text-xs text-ink-400">{t("Set up a free AI provider on the server (AI_PROVIDER in the backend .env) for a written weekly summary.")}</p>}
        </div>
      )}
    </Panel>
  );
}
