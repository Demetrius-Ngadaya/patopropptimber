"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money, toNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { ButtonLink, DataTable, PageHeader, Pagination, Panel, StatusBadge, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Row = { id: number; number: string; status: string; issued_at: string; expected_amount: string; sold_amount: string; amount_received: string; shortage: string; items_count: number; seller: { name: string }; branch: { name: string } };

export default function AllocationsPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [status, setStatus] = useState("open");
  const [page, setPage] = useState(1);
  const list = useApi<Paginated<Row>>("admin/allocations", { status, page });

  return (
    <Guard perm={["allocations.view", "allocations.view_own"]}>
      <PageHeader title={t("Seller allocations")} description={t("Stock handed to sellers to sell outside the shop. When they return, record what came back and the money handed in.")} actions={can("allocations.create") && <ButtonLink href="/admin/allocations/new" icon={<Plus className="size-4" />}>{t("Issue stock")}</ButtonLink>} />
      <Panel padded={false}>
        <div className="px-3 pt-2"><Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[{ value: "open", label: t("With sellers") }, { value: "reconciled", label: t("Reconciled") }, { value: "cancelled", label: t("Cancelled") }, { value: "", label: t("All") }]} /></div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/allocations/${r.id}`)} columns={[
          { key: "number", header: t("Allocation"), render: (r) => <span className="font-medium">{r.number}</span> },
          { key: "seller.name", header: t("Seller") },
          { key: "issued_at", header: t("Issued"), render: (r) => dateTime(r.issued_at) },
          { key: "items_count", header: t("Items"), align: "right", hideOnMobile: true },
          { key: "expected_amount", header: t("Value issued"), align: "right", render: (r) => money(r.expected_amount) },
          { key: "amount_received", header: t("Received"), align: "right", hideOnMobile: true, render: (r) => (r.status === "reconciled" ? money(r.amount_received) : "—") },
          { key: "shortage", header: t("Shortage"), align: "right", render: (r) => (toNumber(r.shortage) > 0 ? <span className="font-semibold text-ember-600">{money(r.shortage)}</span> : "—") },
          { key: "status", header: t("Status"), render: (r) => <StatusBadge status={r.status} /> },
        ]} empty={{ title: t("No allocations here") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
