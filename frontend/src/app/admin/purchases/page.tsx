"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, today, toNumber } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { ButtonLink, DataTable, Input, PageHeader, Pagination, Panel, SearchInput, Select, StatusBadge, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Row = { id: number; number: string; purchase_date: string; due_date: string | null; remind_on: string | null; supplier_invoice_no: string | null; supplier_total: string; total: string; balance: string; payment_status: string; items_count: number; supplier?: { name: string } | null; creator?: { name: string } | null };

export default function PurchasesPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const { suppliers = [] } = useLookups(["suppliers"]);
  const [f, setF] = useState({ search: "", supplier_id: "", payment_status: "", due: "", date_from: "", date_to: "" });
  const [page, setPage] = useState(1);
  const search = useDebounced(f.search);
  const list = useApi<Paginated<Row>>("admin/purchases", { ...f, search, page });

  return (
    <Guard perm="purchases.view">
      <PageHeader title={t("Purchases")} description={t("Goods received from suppliers. Receiving a purchase adds the stock.")} actions={can("purchases.create") && <ButtonLink href="/admin/purchases/new" icon={<Plus className="size-4" />}>{t("Receive goods")}</ButtonLink>} />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs value={f.due} onChange={(v) => { setF({ ...f, due: v, payment_status: v ? "" : f.payment_status }); setPage(1); }} tabs={[
            { value: "", label: t("All purchases") }, { value: "overdue", label: t("Payment overdue") }, { value: "week", label: t("To pay this week") },
          ]} />
        </div>
        <div className="grid gap-3 border-b border-ink-100 p-3 sm:grid-cols-2 lg:grid-cols-5">
          <SearchInput value={f.search} onChange={(v) => setF({ ...f, search: v })} placeholder={t("Purchase or supplier invoice no.")} />
          <Select aria-label={t("Supplier")} value={f.supplier_id} onChange={(e) => setF({ ...f, supplier_id: e.target.value })}><option value="">{t("All suppliers")}</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>
          <Select aria-label={t("Payment")} value={f.payment_status} onChange={(e) => setF({ ...f, payment_status: e.target.value })}><option value="">{t("Any payment")}</option><option value="owed">{t("Still owed")}</option><option value="paid">{t("Paid")}</option><option value="partial">{t("Part paid")}</option><option value="unpaid">{t("Unpaid")}</option></Select>
          <Input aria-label={t("From date")} type="date" value={f.date_from} onChange={(e) => setF({ ...f, date_from: e.target.value })} />
          <Input aria-label={t("To date")} type="date" value={f.date_to} onChange={(e) => setF({ ...f, date_to: e.target.value })} />
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/purchases/${r.id}`)} columns={[
          { key: "number", header: t("Purchase"), render: (r) => <span className="font-medium">{r.number}</span> },
          { key: "purchase_date", header: t("Date"), render: (r) => date(r.purchase_date) },
          { key: "supplier.name", header: t("Supplier") },
          { key: "supplier_invoice_no", header: t("Their invoice"), hideOnMobile: true },
          { key: "items_count", header: t("Items"), align: "right", hideOnMobile: true },
          { key: "total", header: t("Landed total"), align: "right", render: (r) => money(r.total) },
          { key: "balance", header: t("Unpaid"), align: "right", render: (r) => (toNumber(r.balance) > 0 ? <span className="font-semibold text-ember-600">{money(r.balance)}</span> : "—") },
          {
            key: "due_date", header: t("Pay by"), hideOnMobile: true, render: (r) => {
              if (!r.due_date || toNumber(r.balance) <= 0) return "—";
              const late = r.due_date.slice(0, 10) < today();
              return <span className={late ? "font-medium text-ember-600" : undefined}>{date(r.due_date)}{r.remind_on && <span className="block text-xs text-ink-500">{t("Reminder {date}", { date: date(r.remind_on) })}</span>}</span>;
            },
          },
          { key: "payment_status", header: t("Payment"), render: (r) => <StatusBadge status={r.payment_status} /> },
        ]} empty={{ title: t("No purchases recorded") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
