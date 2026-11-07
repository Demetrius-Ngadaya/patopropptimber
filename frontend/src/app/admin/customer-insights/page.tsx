"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Megaphone } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { cn, date, money } from "@/lib/format";
import { useApi, useDebounced, useUrlParam } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, PageHeader, Pagination, Panel, SearchInput, Select, Stat } from "@/components/ui";
import { SEGMENT_KEYS, SEGMENTS, type Segment } from "@/features/marketing/segments";

type Row = {
  customer_id: number;
  customer: { id: number; code: string; name: string; phone: string | null; email: string | null; balance: string };
  purchases: number;
  lifetime_spend: number;
  spend_365: number;
  visits_90: number;
  visits_prev_90: number;
  average_basket: number;
  first_purchase_at: string;
  last_purchase_at: string;
  days_since_last: number;
  segments: Segment[];
};
type Ranked = { id: number; name: string; value: number };
type Response = Paginated<Row> & {
  counts: Record<Segment, number>;
  summary: { buying_customers: number; spend_365: number; vip_share_percent: number };
  best: Ranked[];
  most_frequent: Ranked[];
};

export default function CustomerInsightsPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [segment, setSegment] = useState<Segment | "">("");
  const [sort, setSort] = useState("spend");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  useUrlParam("segment", (v) => setSegment(v as Segment));
  const q = useApi<Response>("admin/customer-insights", { segment, sort, search: useDebounced(search), page });
  const d = q.data;

  return (
    <Guard perm="marketing.insights">
      <PageHeader
        title={t("Customer insights")}
        description={t("Who your best customers are, who comes often, and who is coming less or has stopped, worked out from completed sales. Thresholds are in Settings → Customer insights.")}
        actions={can("marketing.manage") && <Link href={`/admin/promotions/new${segment ? `?segment=${segment}` : ""}`}><Button icon={<Megaphone className="size-4" />}>{segment ? t("Promotion for this group") : t("New promotion")}</Button></Link>}
      />

      {d && (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label={t("Customers who bought")} value={d.summary.buying_customers.toLocaleString()} />
          <Stat label={t("Their spending (12 months)")} value={money(d.summary.spend_365)} tone="brand" />
          <Stat label={t("Share from VIP customers")} value={`${d.summary.vip_share_percent}%`} sub={t("{count} VIP customers", { count: d.counts.vip ?? 0 })} />
          <Stat label={t("Need attention")} value={((d.counts.declining ?? 0) + (d.counts.lost ?? 0)).toLocaleString()} sub={t("coming less or stopped")} tone="ember" />
        </div>
      )}

      <div className="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
        {SEGMENT_KEYS.map((s) => {
          const meta = SEGMENTS[s];
          const on = segment === s;
          return (
            <button key={s} type="button" onClick={() => { setSegment(on ? "" : s); setPage(1); }} aria-pressed={on}
              className={cn("rounded-[var(--radius-panel)] p-3 text-left ring-1 transition-colors", on ? "bg-brand-50 ring-brand-500" : "bg-surface ring-ink-200/70 hover:ring-brand-300")}>
              <span className="flex items-center justify-between">
                <meta.icon className={cn("size-5", on ? "text-brand-700" : "text-ink-400")} aria-hidden />
                <span className="num text-lg font-semibold text-ink-900">{d?.counts[s] ?? "…"}</span>
              </span>
              <span className="mt-1 block text-sm font-medium text-ink-900">{t(meta.label)}</span>
              <span className="block text-xs text-ink-500">{t(meta.hint)}</span>
            </button>
          );
        })}
      </div>

      <div className="grid gap-5 xl:grid-cols-[1fr_300px]">
        <Panel padded={false}>
          <div className="flex flex-wrap items-center gap-2 border-b border-ink-100 p-3">
            <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Name, phone or code")} className="w-full max-w-xs" />
            <Select value={sort} onChange={(e) => setSort(e.target.value)} className="w-auto" aria-label={t("Sort by")}>
              <option value="spend">{t("Most spent (12 months)")}</option>
              <option value="visits">{t("Most visits (90 days)")}</option>
              <option value="recent">{t("Bought most recently")}</option>
              <option value="oldest_visit">{t("Longest since last purchase")}</option>
              <option value="lifetime">{t("Most spent ever")}</option>
            </Select>
            {segment && <Badge tone={SEGMENTS[segment].tone}>{t(SEGMENTS[segment].label)}</Badge>}
          </div>
          <DataTable
            rows={d?.data}
            loading={q.isFetching}
            rowKey={(r) => r.customer_id}
            onRowClick={(r) => router.push(`/admin/customers/${r.customer_id}`)}
            columns={[
              { key: "name", header: t("Customer"), render: (r) => <span className="font-medium text-ink-900">{r.customer.name}<span className="block text-xs font-normal text-ink-500">{r.customer.phone ?? r.customer.code}</span></span> },
              { key: "segments", header: t("Group"), render: (r) => <span className="flex flex-wrap gap-1">{r.segments.map((s) => <Badge key={s} tone={SEGMENTS[s]?.tone}>{t(SEGMENTS[s]?.label ?? s)}</Badge>)}</span> },
              { key: "spend_365", header: t("Spent (12 months)"), align: "right", render: (r) => <span className="num font-medium">{money(r.spend_365)}</span> },
              {
                key: "visits_90", header: t("Visits (90 days)"), align: "right", hideOnMobile: true, render: (r) => {
                  const change = r.visits_90 - r.visits_prev_90;
                  return <span className="num">{r.visits_90}{change !== 0 && <span className={cn("ml-1 text-xs", change > 0 ? "text-brand-700" : "text-ember-600")}>{change > 0 ? `+${change}` : change}</span>}</span>;
                },
              },
              { key: "average_basket", header: t("Average purchase"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.average_basket)}</span> },
              { key: "last_purchase_at", header: t("Last purchase"), render: (r) => <span>{date(r.last_purchase_at)}<span className={cn("block text-xs", r.days_since_last > 60 ? "text-ember-600" : "text-ink-500")}>{t("{days} days ago", { days: r.days_since_last })}</span></span> },
            ]}
            empty={{ title: t("No customers in this group"), message: t("Customers appear here once they have completed purchases linked to their name.") }}
          />
          <Pagination meta={d?.meta} onPage={setPage} />
        </Panel>

        <div className="space-y-5">
          <Ranking title={t("Best customers (12 months)")} rows={d?.best} format={(v) => money(v)} />
          <Ranking title={t("Most frequent (90 days)")} rows={d?.most_frequent} format={(v) => t("{count} visits", { count: v })} />
        </div>
      </div>
    </Guard>
  );
}

function Ranking({ title, rows, format }: { title: string; rows?: Ranked[]; format: (v: number) => string }) {
  const t = useT();
  return (
    <Panel title={title}>
      {!rows?.length ? <p className="text-sm text-ink-500">{t("No purchases yet.")}</p> : (
        <ol className="space-y-2">
          {rows.map((r, i) => (
            <li key={r.id} className="flex items-center gap-2.5 text-sm">
              <span className={cn("grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold", i === 0 ? "bg-saffron-100 text-saffron-800" : "bg-ink-100 text-ink-600")}>{i + 1}</span>
              <Link href={`/admin/customers/${r.id}`} className="min-w-0 flex-1 truncate text-ink-900 hover:underline">{r.name}</Link>
              <span className="num shrink-0 text-ink-700">{format(r.value)}</span>
            </li>
          ))}
        </ol>
      )}
    </Panel>
  );
}
