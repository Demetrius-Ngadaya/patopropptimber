"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money } from "@/lib/format";
import { useApi, useUrlParam } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Badge, ButtonLink, DataTable, PageHeader, Pagination, Panel, Select, StatusBadge, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Row = { id: number; number: string; type: string; status: string; sent_at: string; total_cost: string; total_amount: string; payment_status: string; items_count: number; from_branch: { name: string }; to_branch: { name: string }; sender?: { name: string } };
const typeLabel: Record<string, string> = { free: "Free move", sale: "Sold to branch", credit: "On credit" };

export default function TransfersPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [direction, setDirection] = useState("all");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  useUrlParam("direction", setDirection);
  useUrlParam("status", setStatus);
  const list = useApi<Paginated<Row>>("admin/transfers", { direction, status, page });

  return (
    <Guard perm="transfers.view">
      <PageHeader title={t("Branch transfers")} description={t("Stock leaves the sending branch straight away and arrives when the receiving branch confirms it.")} actions={can("transfers.create") && <ButtonLink href="/admin/transfers/new" icon={<Plus className="size-4" />}>{t("Send stock")}</ButtonLink>} />
      <Panel padded={false}>
        <div className="flex flex-wrap items-end justify-between gap-3 px-3 pt-2">
          <Tabs value={direction} onChange={(v) => { setDirection(v); setPage(1); }} tabs={[{ value: "all", label: t("All") }, { value: "in", label: t("Coming in") }, { value: "out", label: t("Going out") }]} />
          <Select aria-label={t("Status")} className="mb-2 h-9 w-44" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">{t("Any status")}</option><option value="in_transit">{t("In transit")}</option><option value="received">{t("Received")}</option><option value="cancelled">{t("Cancelled")}</option></Select>
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/transfers/${r.id}`)} columns={[
          { key: "number", header: t("Transfer"), render: (r) => <span className="font-medium">{r.number}</span> },
          { key: "sent_at", header: t("Sent"), render: (r) => dateTime(r.sent_at) },
          { key: "route", header: t("From → to"), render: (r) => <>{r.from_branch.name} → {r.to_branch.name}</> },
          { key: "type", header: t("Type"), hideOnMobile: true, render: (r) => <Badge tone={r.type === "free" ? "neutral" : "blue"}>{typeLabel[r.type]}</Badge> },
          { key: "items_count", header: t("Items"), align: "right", hideOnMobile: true },
          { key: "value", header: t("Value"), align: "right", render: (r) => money(r.type === "free" ? r.total_cost : r.total_amount) },
          { key: "status", header: t("Status"), render: (r) => <span className="flex gap-1"><StatusBadge status={r.status} />{r.payment_status === "unpaid" && <StatusBadge status="unpaid" />}</span> },
        ]} empty={{ title: t("No transfers") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
