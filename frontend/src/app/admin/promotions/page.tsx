"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { date, money } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, PageHeader, Pagination, Panel, SearchInput, Tabs } from "@/components/ui";
import { SEGMENTS } from "@/features/marketing/segments";
import { offerText, STATE_LABELS, STATE_TONES, type Promotion } from "@/features/marketing/types";

type State = "running" | "scheduled" | "ended" | "";

export default function PromotionsPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const { settings } = usePublicSettings();
  const [state, setState] = useState<State>("running");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const list = useApi<Paginated<Promotion>>("admin/promotions", { state, search: useDebounced(search), page });

  return (
    <Guard perm="marketing.promotions">
      <PageHeader
        title={t("Promotions")}
        description={t("Discounts for all customers or for chosen groups (VIP, customers coming less...). They are applied at the till, and customers can be told by SMS, WhatsApp or email.")}
        actions={can("marketing.manage") && <Link href="/admin/promotions/new"><Button icon={<Plus className="size-4" />}>{t("New promotion")}</Button></Link>}
      />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs<State> value={state} onChange={(v) => { setState(v); setPage(1); }} tabs={[
            { value: "running", label: t("Running") }, { value: "scheduled", label: t("Starts later") }, { value: "ended", label: t("Ended") }, { value: "", label: t("All") },
          ]} />
        </div>
        <div className="border-b border-ink-100 p-3"><SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Name or code")} className="w-full max-w-sm" /></div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/promotions/${r.id}`)}
          columns={[
            { key: "name", header: t("Promotion"), render: (r) => <span className="font-medium text-ink-900">{r.name}<span className="block text-xs font-normal text-ink-500">{r.code}</span></span> },
            { key: "offer", header: t("Offer"), render: (r) => <span className="font-medium text-brand-700">{offerText(r, t, settings?.currency)}</span> },
            { key: "audience", header: t("For"), hideOnMobile: true, render: (r) => r.audience === "all" ? t("All customers") : r.audience === "customers" ? t("{count} chosen customers", { count: r.customer_ids?.length ?? 0 }) : (r.segments ?? []).map((s) => t(SEGMENTS[s]?.label ?? s)).join(", ") },
            { key: "dates", header: t("Dates"), hideOnMobile: true, render: (r) => `${date(r.starts_on)} – ${date(r.ends_on)}` },
            { key: "used", header: t("Used"), align: "right", render: (r) => <span className="num">{r.redemptions_count ?? 0}<span className="block text-xs text-ink-500">{money(r.redemptions_sum_amount ?? 0)}</span></span> },
            { key: "state", header: t("State"), render: (r) => <Badge tone={STATE_TONES[r.state]}>{t(STATE_LABELS[r.state])}</Badge> },
          ]}
          empty={{ title: t("No promotions here"), action: can("marketing.manage") ? <Link href="/admin/promotions/new"><Button icon={<Plus className="size-4" />}>{t("New promotion")}</Button></Link> : undefined }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
