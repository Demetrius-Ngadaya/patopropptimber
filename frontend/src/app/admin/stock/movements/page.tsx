"use client";

import Link from "next/link";
import { useState } from "react";
import type { Paginated } from "@/lib/api";
import { dateTime, qty, humanize, toNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import type { Product } from "@/lib/types";
import { Guard } from "@/components/layout/Guard";
import { ProductPicker } from "@/features/catalog/components/ProductPicker";
import { DataTable, Input, PageHeader, Pagination, Panel, Select } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Row = { id: number; type: string; quantity: string; balance_after: string; note: string | null; created_at: string; product: { id: number; name: string }; branch: { name: string }; user?: { name: string } | null; batch?: { batch_no: string | null; expiry_date: string | null } | null };

const types = ["opening", "purchase", "sale", "sale_return", "sale_cancel", "purchase_return", "transfer_out", "transfer_in", "transfer_cancel", "allocation_out", "allocation_return", "adjustment_in", "adjustment_out", "damage", "expired"];

export default function MovementsPage() {
  const tr = useT();
  const [product, setProduct] = useState<Product | null>(null);
  const [f, setF] = useState({ type: "", date_from: "", date_to: "" });
  const [page, setPage] = useState(1);
  const list = useApi<Paginated<Row>>("admin/stock/movements", { ...f, product_id: product?.id, page });

  return (
    <Guard perm="stock.movements">
      <PageHeader title={tr("Stock ledger")} description={tr("Every unit in and out, with who did it and why. Nothing is ever edited or removed here.")} />
      <Panel padded={false}>
        <div className="grid gap-3 border-b border-ink-100 p-3 md:grid-cols-[2fr_1fr_1fr_1fr]">
          {product ? (
            <div className="flex items-center justify-between rounded-md bg-brand-50 px-3 text-sm"><span className="font-medium">{product.name}</span><button type="button" className="text-brand-700 underline" onClick={() => setProduct(null)}>{tr("Clear")}</button></div>
          ) : <ProductPicker source="admin/products" onSelect={setProduct} placeholder={tr("Filter by product")} showStock={false} />}
          <Select aria-label={tr("Movement type")} value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}><option value="">{tr("All movements")}</option>{types.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</Select>
          <Input aria-label={tr("From date")} type="date" value={f.date_from} onChange={(e) => setF({ ...f, date_from: e.target.value })} />
          <Input aria-label={tr("To date")} type="date" value={f.date_to} onChange={(e) => setF({ ...f, date_to: e.target.value })} />
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} columns={[
          { key: "created_at", header: tr("Date"), render: (r) => dateTime(r.created_at) },
          { key: "product.name", header: tr("Product"), render: (r) => <Link href={`/admin/products/${r.product.id}`} className="font-medium hover:underline">{r.product.name}</Link> },
          { key: "branch.name", header: tr("Branch"), hideOnMobile: true },
          { key: "type", header: tr("Type"), render: (r) => humanize(r.type) },
          { key: "quantity", header: tr("Change"), align: "right", render: (r) => <span className={toNumber(r.quantity) < 0 ? "text-ember-600" : "text-brand-700"}>{toNumber(r.quantity) > 0 ? "+" : ""}{qty(r.quantity)}</span> },
          { key: "balance_after", header: tr("Balance"), align: "right", render: (r) => qty(r.balance_after) },
          { key: "batch", header: tr("Batch"), hideOnMobile: true, render: (r) => r.batch?.batch_no ?? (r.batch?.expiry_date ? `exp ${r.batch.expiry_date.slice(0, 10)}` : "—") },
          { key: "note", header: tr("Reference"), hideOnMobile: true },
          { key: "user.name", header: tr("By"), hideOnMobile: true },
        ]} empty={{ title: tr("No movements match") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
