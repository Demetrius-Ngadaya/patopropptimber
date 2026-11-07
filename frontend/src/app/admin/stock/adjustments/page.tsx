"use client";

import { useState } from "react";
import { Plus } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, qty, humanize, toNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { AdjustStockModal } from "@/features/inventory/components/AdjustStockModal";
import { Button, DataTable, PageHeader, Pagination, Panel, Select } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Row = { id: number; number: string; type: string; quantity: string; quantity_before: string; quantity_after: string; reason: string; created_at: string; product: { name: string }; branch: { name: string }; creator?: { name: string } | null };

export default function AdjustmentsPage() {
  const t = useT();
  const { can } = useAuth();
  const [type, setType] = useState("");
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(false);
  const list = useApi<Paginated<Row>>("admin/stock/adjustments", { type, page });

  return (
    <Guard perm={["stock.view", "stock.adjust"]}>
      <PageHeader title={t("Stock adjustments")} description={t("Stock counts, damages, expiry write-offs and other corrections.")} actions={can("stock.adjust") && <Button icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t("New adjustment")}</Button>} />
      <Panel padded={false}>
        <div className="border-b border-ink-100 p-3">
          <Select aria-label={t("Type")} className="max-w-xs" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
            <option value="">{t("All types")}</option><option value="count">{t("Stock counts")}</option><option value="damage">{t("Damaged")}</option><option value="expired">{t("Expired")}</option><option value="increase">{t("Increases")}</option><option value="decrease">{t("Decreases")}</option>
          </Select>
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} columns={[
          { key: "number", header: "No." },
          { key: "created_at", header: t("Date"), render: (r) => dateTime(r.created_at) },
          { key: "product.name", header: t("Product"), render: (r) => <span className="font-medium">{r.product.name}</span> },
          { key: "type", header: t("Type"), render: (r) => humanize(r.type) },
          { key: "quantity", header: t("Change"), align: "right", render: (r) => <span className={toNumber(r.quantity) < 0 ? "text-ember-600" : "text-brand-700"}>{toNumber(r.quantity) > 0 ? "+" : ""}{qty(r.quantity)}</span> },
          { key: "quantity_after", header: t("Before → after"), align: "right", hideOnMobile: true, render: (r) => `${qty(r.quantity_before)} → ${qty(r.quantity_after)}` },
          { key: "reason", header: t("Reason"), hideOnMobile: true },
          { key: "creator.name", header: t("By"), hideOnMobile: true },
        ]} empty={{ title: t("No adjustments recorded") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      {open && <AdjustStockModal open={open} onClose={() => setOpen(false)} />}
    </Guard>
  );
}
