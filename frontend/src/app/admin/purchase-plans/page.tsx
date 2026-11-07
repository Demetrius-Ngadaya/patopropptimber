"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, PageHeader, Pagination, Panel, SearchInput, Tabs } from "@/components/ui";
import { PLAN_STATUS_LABELS, PLAN_STATUS_TONES, type PlanStatus, type PurchasePlan } from "@/features/purchase-plans/types";

export default function PurchasePlansPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [status, setStatus] = useState<PlanStatus | "">("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const list = useApi<Paginated<PurchasePlan> & { counts: Record<PlanStatus, number> }>("admin/purchase-plans", { status, search: useDebounced(search), page });
  const counts = list.data?.counts;

  return (
    <Guard perm="purchase_plans.view">
      <PageHeader title={t("Purchase planning")} description={t("Plan what to buy, approve it, then receive the goods in one or more deliveries. Credit plans set when to pay and when to be reminded.")}
        actions={can("purchase_plans.create") && <Link href="/admin/purchase-plans/new"><Button icon={<Plus className="size-4" />}>{t("New purchase plan")}</Button></Link>} />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[
            { value: "" as const, label: t("All") },
            ...(Object.keys(PLAN_STATUS_LABELS) as PlanStatus[]).map((s) => ({ value: s, label: t(PLAN_STATUS_LABELS[s]), count: counts?.[s] })),
          ]} />
        </div>
        <div className="border-b border-ink-100 p-3"><SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Number or title")} className="w-full max-w-sm" /></div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/purchase-plans/${r.id}`)}
          columns={[
            { key: "number", header: t("Plan"), render: (r) => <span className="font-medium">{r.number}<span className="block text-xs font-normal text-ink-500">{r.title ?? date(r.created_at)}</span></span> },
            { key: "supplier", header: t("Supplier"), render: (r) => r.supplier?.name ?? <span className="text-ink-400">{t("Not chosen yet")}</span> },
            { key: "expected_date", header: t("Expected"), hideOnMobile: true, render: (r) => date(r.expected_date) },
            { key: "payment_type", header: t("Payment"), hideOnMobile: true, render: (r) => r.payment_type === "credit" ? t("Credit, {days} days", { days: r.credit_days ?? 0 }) : t("Cash") },
            { key: "estimated_total", header: t("Planned value"), align: "right", render: (r) => money(r.estimated_total) },
            { key: "received_total", header: t("Received value"), align: "right", hideOnMobile: true, render: (r) => money(r.received_total) },
            { key: "status", header: t("Stage"), render: (r) => <Badge tone={PLAN_STATUS_TONES[r.status]}>{t(PLAN_STATUS_LABELS[r.status])}</Badge> },
          ]}
          empty={{ title: t("No purchase plans"), action: can("purchase_plans.create") ? <Link href="/admin/purchase-plans/new"><Button icon={<Plus className="size-4" />}>{t("New purchase plan")}</Button></Link> : undefined }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
