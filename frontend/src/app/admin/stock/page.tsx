"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import type { Paginated } from "@/lib/api";
import { date, qty } from "@/lib/format";
import { useApi, useUrlParam } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { DataTable, PageHeader, Pagination, Panel, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Row = { product_id: number; name: string; branch: string; quantity: string; unit?: string; reorder_level?: string; sku?: string; batch_no?: string; expiry_date?: string; batch_id?: number };
type Kind = "low" | "out" | "expiring" | "expired";

export default function StockAlertsPage() {
  const t = useT();
  const router = useRouter();
  const [type, setType] = useState<Kind>("low");
  const [page, setPage] = useState(1);
  useUrlParam("type", (v) => setType(v as Kind));
  const list = useApi<Paginated<Row> & { counts: Record<string, number> }>("admin/stock/alerts", { type, page });
  const c = list.data?.counts ?? {};
  const isBatch = type === "expiring" || type === "expired";

  return (
    <Guard perm="stock.alerts">
      <PageHeader title={t("Stock alerts")} description={t("What needs reordering, what has run out, and batches that are expiring or already expired.")} />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs<Kind> value={type} onChange={(v) => { setType(v); setPage(1); }} tabs={[
            { value: "low", label: t("Running low"), count: c.low_stock },
            { value: "out", label: t("Out of stock"), count: c.out_of_stock },
            { value: "expiring", label: t("Expiring soon"), count: c.expiring_batches },
            { value: "expired", label: t("Expired"), count: c.expired_batches },
          ]} />
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => `${r.product_id}-${r.batch_id ?? r.branch}`}
          onRowClick={(r) => router.push(`/admin/products/${r.product_id}`)}
          columns={[
            { key: "name", header: t("Product"), render: (r) => <span className="font-medium">{r.name}</span> },
            { key: "branch", header: t("Branch") },
            ...(isBatch ? [
              { key: "batch_no", header: t("Batch") },
              { key: "expiry_date", header: t("Expiry"), render: (r: Row) => <span className={type === "expired" ? "font-semibold text-ember-600" : "text-saffron-700"}>{date(r.expiry_date)}</span> },
            ] : [{ key: "reorder_level", header: t("Reorder at"), align: "right" as const, render: (r: Row) => qty(r.reorder_level, r.unit) }]),
            { key: "quantity", header: t("Quantity"), align: "right", render: (r) => <span className="font-semibold">{qty(r.quantity, r.unit)}</span> },
          ]}
          empty={{ title: t("Nothing to worry about here"), message: type === "low" ? t("No product is at or below its reorder level.") : undefined }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
