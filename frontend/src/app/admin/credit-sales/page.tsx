"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import type { Paginated } from "@/lib/api";
import { date, money, toNumber } from "@/lib/format";
import { useApi, useDebounced, useUrlParam } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, DataTable, PageHeader, Pagination, Panel, SearchInput, Stat, Tabs } from "@/components/ui";
import type { CreditSaleRow } from "@/features/credit/types";

type Status = "outstanding" | "overdue" | "due_today" | "paid" | "";
type Summary = { count: number; original_owed: number; paid: number; outstanding: number; overdue: number };

export default function CreditSalesPage() {
  const t = useT();
  const router = useRouter();
  const [status, setStatus] = useState<Status>("outstanding");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  useUrlParam("status", (v) => setStatus(v as Status));
  const q = useDebounced(search);
  const list = useApi<Paginated<CreditSaleRow> & { summary: Summary }>("admin/credit-sales", { status, search: q, page });
  const s = list.data?.summary;
  const todayStr = new Date().toISOString().slice(0, 10);

  return (
    <Guard perm="credit.view">
      <PageHeader title={t("Credit sales")} description={t("Goods taken now and paid later. Record full or partial repayments and follow every debt until it is settled.")} />
      {s && (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label={t("Originally owed")} value={money(s.original_owed)} sub={t("{count} credit sale(s)", { count: s.count })} />
          <Stat label={t("Repaid")} value={money(s.paid)} tone="brand" />
          <Stat label={t("Still owed")} value={money(s.outstanding)} tone="saffron" />
          <Stat label={t("Overdue")} value={money(s.overdue)} tone="ember" />
        </div>
      )}
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs<Status>
            value={status}
            onChange={(v) => { setStatus(v); setPage(1); }}
            tabs={[
              { value: "outstanding", label: t("Not yet paid") },
              { value: "overdue", label: t("Overdue") },
              { value: "due_today", label: t("Due today") },
              { value: "paid", label: t("Fully paid") },
              { value: "", label: t("All") },
            ]}
          />
        </div>
        <div className="border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Invoice, customer name or phone")} className="w-full max-w-sm" />
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/credit-sales/${r.id}`)}
          columns={[
            { key: "number", header: t("Invoice"), render: (r) => <span className="font-medium text-ink-900">{r.number}<span className="block text-xs font-normal text-ink-500">{date(r.sold_at)}</span></span> },
            { key: "customer", header: t("Customer"), render: (r) => <span>{r.customer?.name ?? r.customer_name ?? "—"}<span className="block text-xs text-ink-500">{r.customer?.phone ?? r.customer_phone ?? ""}</span></span> },
            { key: "credit_amount", header: t("Owed"), align: "right", render: (r) => <span className="num">{money(toNumber(r.credit_amount) || toNumber(r.balance))}</span> },
            { key: "paid", header: t("Paid"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(Math.max(0, (toNumber(r.credit_amount) || toNumber(r.balance)) - toNumber(r.balance)))}</span> },
            { key: "balance", header: t("Remaining"), align: "right", render: (r) => toNumber(r.balance) > 0 ? <span className="num font-semibold text-ember-600">{money(r.balance)}</span> : <Badge tone="green">{t("Paid")}</Badge> },
            {
              key: "due_date", header: t("Pay by"), render: (r) => {
                if (!r.due_date) return "—";
                const late = toNumber(r.balance) > 0 && r.due_date.slice(0, 10) < todayStr;
                return <span className={late ? "font-medium text-ember-600" : undefined}>{date(r.due_date)}{late && <Badge tone="red" className="ml-1.5">{t("Overdue")}</Badge>}</span>;
              },
            },
          ]}
          empty={{ title: status === "overdue" ? t("No overdue debts. Well done!") : t("No credit sales found") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
