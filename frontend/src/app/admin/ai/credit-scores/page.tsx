"use client";

import Link from "next/link";
import { useState } from "react";
import type { ApiResponse } from "@/lib/api";
import { cn, date, money } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, DataTable, Modal, PageHeader, Panel, SearchInput, Spinner, Stat } from "@/components/ui";
import { BAND_TONE, type CreditScoreDetail, type CreditScoreRow } from "@/features/ai/types";

function ScoreRing({ score }: { score: number }) {
  const color = score >= 65 ? "var(--color-brand-600)" : score >= 50 ? "var(--color-saffron-500)" : "var(--color-ember-600)";
  return (
    <div className="relative grid size-28 place-items-center rounded-full" style={{ background: `conic-gradient(${color} ${score * 3.6}deg, var(--color-ink-100) 0deg)` }}>
      <div className="grid size-22 place-items-center rounded-full bg-surface"><span className="num text-3xl font-bold">{score}</span></div>
    </div>
  );
}

function Detail({ id, onClose }: { id: number | null; onClose: () => void }) {
  const t = useT();
  const q = useApi<ApiResponse<CreditScoreDetail>>(id ? `admin/ai/credit-scores/${id}` : null, undefined, { keepPrevious: false });
  if (!id) return null;
  const d = q.data?.data;
  return (
    <Modal open onClose={onClose} size="lg" title={d?.customer.name ?? t("Credit score")} description={d?.customer.phone ?? undefined}>
      {!d ? <Spinner /> : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-6">
            <ScoreRing score={d.score} />
            <div className="space-y-1 text-sm">
              <p><Badge tone={BAND_TONE[d.band]}>{t("Band {band}", { band: d.band })}</Badge></p>
              <p>{t("Current limit")}: <span className="num font-semibold">{money(d.summary.current_limit)}</span></p>
              <p>{t("Suggested limit")}: <span className="num font-semibold text-brand-700">{money(d.suggested_limit)}</span></p>
              <p className="text-ink-500">{t("{n} credit sales, {s} settled. Owes {o}, of which {od} overdue.", { n: d.summary.credit_sales, s: d.summary.settled, o: money(d.summary.outstanding), od: money(d.summary.overdue) })}</p>
            </div>
          </div>
          <div>
            <p className="mb-2 text-xs font-semibold uppercase text-ink-500">{t("Why this score")}</p>
            <ul className="space-y-2">
              {d.factors.map((f) => (
                <li key={f.key} className="text-sm">
                  <div className="flex justify-between"><span className="font-medium">{f.label}</span><span className="num text-ink-500">{f.points} / {f.max}</span></div>
                  <div className="my-1 h-1.5 overflow-hidden rounded-full bg-ink-100"><div className={cn("h-full rounded-full", f.points / f.max >= 0.65 ? "bg-brand-500" : f.points / f.max >= 0.4 ? "bg-saffron-500" : "bg-ember-500")} style={{ width: `${(f.points / Math.max(1, f.max)) * 100}%` }} /></div>
                  <p className="text-xs text-ink-500">{f.reason}</p>
                </li>
              ))}
            </ul>
          </div>
          {d.history.length > 1 && <p className="text-xs text-ink-500">{t("Past scores")}: {d.history.map((h) => `${h.score} (${date(h.computed_at)})`).join(" · ")}</p>}
          <Link href={`/admin/customers/${d.customer.id}`} className="text-sm font-medium text-brand-700 hover:underline">{t("Open customer")} →</Link>
        </div>
      )}
    </Modal>
  );
}

export default function CreditScoresPage() {
  const t = useT();
  const [search, setSearch] = useState("");
  const [open, setOpen] = useState<number | null>(null);
  const q = useApi<ApiResponse<CreditScoreRow[]>>("admin/ai/credit-scores", { search: useDebounced(search) || undefined });
  const rows = q.data?.data ?? [];
  const risky = rows.filter((r) => r.band === "D" || r.band === "E");
  const overLimit = rows.filter((r) => r.credit_limit > r.suggested_limit * 1.25 && r.credit_limit > 0);

  return (
    <Guard perm="ai.credit_score">
      <PageHeader title={t("Credit scores")} description={t("A 0–100 score for every credit customer from how they actually pay: on time or late, how much they owe against their limit, and how long they have bought from you. Updated weekly and on demand.")} />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={t("Credit customers")} value={rows.length} />
        <Stat label={t("High risk (D–E)")} value={risky.length} tone="ember" />
        <Stat label={t("Owed by high risk")} value={money(risky.reduce((s, r) => s + r.balance, 0))} tone="ember" />
        <Stat label={t("Limit above suggestion")} value={overLimit.length} tone="saffron" />
      </div>
      <Panel padded={false}>
        <div className="border-b border-ink-100 p-3"><SearchInput value={search} onChange={setSearch} placeholder={t("Name or phone")} className="w-full max-w-sm" /></div>
        <DataTable rows={rows} loading={q.isFetching} rowKey={(r) => r.customer_id} onRowClick={(r) => setOpen(r.customer_id)}
          columns={[
            { key: "name", header: t("Customer"), render: (r) => <span className="font-medium">{r.name}<span className="block text-xs font-normal text-ink-500">{r.phone}</span></span> },
            { key: "score", header: t("Score"), align: "right", render: (r) => <span className="inline-flex items-center gap-2"><span className="num font-semibold">{r.score}</span><Badge tone={BAND_TONE[r.band]}>{r.band}</Badge></span> },
            { key: "balance", header: t("Owes"), align: "right", render: (r) => <span className="num">{money(r.balance)}{r.overdue > 0 && <span className="block text-xs text-ember-600">{t("{amount} overdue", { amount: money(r.overdue) })}</span>}</span> },
            { key: "credit_limit", header: t("Limit"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.credit_limit)}</span> },
            { key: "suggested_limit", header: t("Suggested limit"), align: "right", hideOnMobile: true, render: (r) => <span className={cn("num", r.credit_limit > r.suggested_limit * 1.25 && r.credit_limit > 0 && "font-semibold text-saffron-700")}>{money(r.suggested_limit)}</span> },
          ]}
          empty={{ title: t("No credit customers yet") }} />
      </Panel>
      <Detail id={open} onClose={() => setOpen(null)} />
    </Guard>
  );
}
