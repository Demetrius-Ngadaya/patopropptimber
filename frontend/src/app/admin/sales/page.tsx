"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import type { Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import type { Sale } from "@/lib/types";
import { Guard } from "@/components/layout/Guard";
import { DataTable, Input, PageHeader, Pagination, Panel, SearchInput, Select, StatusBadge } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function SalesPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [filters, setFilters] = useState({ search: "", status: "", payment_status: "", channel: "", date_from: "", date_to: "" });
  const [page, setPage] = useState(1);
  const search = useDebounced(filters.search);
  const list = useApi<Paginated<Sale>>("admin/sales", { ...filters, search, page });
  const set = (k: keyof typeof filters, v: string) => {
    setFilters((f) => ({ ...f, [k]: v }));
    setPage(1);
  };

  return (
    <Guard perm="sales.view">
      <PageHeader title={t("Sales")} description={can("sales.view_all_users") ? t("All sales in the selected branch.") : t("Sales you have made.")} />
      <Panel padded={false}>
        <div className="grid gap-3 border-b border-ink-100 p-3 sm:grid-cols-2 lg:grid-cols-6">
          <SearchInput value={filters.search} onChange={(v) => set("search", v)} placeholder={t("Receipt, customer or phone")} className="lg:col-span-2" />
          <Select aria-label={t("Payment status")} value={filters.payment_status} onChange={(e) => set("payment_status", e.target.value)}>
            <option value="">{t("Any payment")}</option>
            <option value="paid">{t("Paid")}</option>
            <option value="partial">{t("Part paid")}</option>
            <option value="unpaid">{t("Unpaid")}</option>
          </Select>
          <Select aria-label={t("Status")} value={filters.status} onChange={(e) => set("status", e.target.value)}>
            <option value="">{t("Any status")}</option>
            <option value="completed">{t("Completed")}</option>
            <option value="cancelled">{t("Cancelled")}</option>
          </Select>
          <Input aria-label={t("From date")} type="date" value={filters.date_from} onChange={(e) => set("date_from", e.target.value)} />
          <Input aria-label={t("To date")} type="date" value={filters.date_to} onChange={(e) => set("date_to", e.target.value)} />
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/sales/${r.id}`)}
          columns={[
            { key: "number", header: t("Receipt"), render: (r) => <span className="font-medium text-ink-900">{r.number}</span> },
            { key: "sold_at", header: t("Date"), render: (r) => dateTime(r.sold_at) },
            { key: "customer_name", header: t("Customer"), render: (r) => r.customer?.name ?? r.customer_name ?? <span className="text-ink-500">{t("Customer")}</span> },
            { key: "cashier.name", header: t("Cashier"), hideOnMobile: true },
            { key: "channel", header: t("Channel"), hideOnMobile: true, render: (r) => <span className="capitalize">{r.channel}</span> },
            { key: "total", header: t("Total"), align: "right", render: (r) => money(r.total) },
            { key: "balance", header: t("Balance"), align: "right", render: (r) => (Number(r.balance) > 0 ? <span className="font-medium text-ember-600">{money(r.balance)}</span> : "—") },
            { key: "payment_status", header: t("Payment"), render: (r) => (r.status === "cancelled" ? <StatusBadge status="cancelled" /> : <StatusBadge status={r.payment_status} />) },
          ]}
          empty={{ title: t("No sales found"), message: t("Try another date range or clear the filters.") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
