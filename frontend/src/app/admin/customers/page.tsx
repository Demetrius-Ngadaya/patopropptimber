"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus, RotateCcw } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, toNumber } from "@/lib/format";
import { useAction, useApi, useDebounced, useUrlParam } from "@/lib/hooks";
import type { Customer } from "@/lib/types";
import { Guard } from "@/components/layout/Guard";
import { CustomerForm } from "@/features/crm/components/CustomerForm";
import { Badge, Button, Checkbox, DataTable, PageHeader, Pagination, Panel, SearchInput, Stat, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Filter = "" | "debtors" | "overdue" | "online";

export default function CustomersPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState<Filter>("");
  const [page, setPage] = useState(1);
  const [trashed, setTrashed] = useState(false);
  const [creating, setCreating] = useState(false);
  useUrlParam("filter", (v) => setFilter(v as Filter));
  const q = useDebounced(search);
  const list = useApi<Paginated<Customer> & { summary: { total_debt: number; debtors: number } }>("admin/customers", { search: q, filter, page, trashed: trashed ? "only" : undefined });
  const restore = useAction((id: number) => api.post(`admin/customers/${id}/restore`), { invalidate: [["admin/customers"]] });

  return (
    <Guard perm="customers.view">
      <PageHeader
        title={t("Customers")}
        description={t("Walk-in, credit and online customers in one list.")}
        actions={can("customers.create") && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t("New customer")}</Button>}
      />
      {list.data?.summary && (
        <div className="mb-5 grid grid-cols-2 gap-3 sm:max-w-md">
          <Stat label={t("Total owed to you")} value={money(list.data.summary.total_debt)} tone="saffron" />
          <Stat label={t("Customers with debt")} value={list.data.summary.debtors} />
        </div>
      )}
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs<Filter>
            value={filter}
            onChange={(v) => { setFilter(v); setPage(1); }}
            tabs={[{ value: "", label: t("All") }, { value: "debtors", label: t("Owe money") }, { value: "overdue", label: t("Overdue") }, { value: "online", label: t("Online accounts") }]}
          />
        </div>
        <div className="flex flex-wrap items-center gap-3 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Name, phone, code or email")} className="w-full max-w-sm" />
          {can("customers.restore") && <Checkbox label={t("Show deleted")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} />}
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={trashed ? undefined : (r) => router.push(`/admin/customers/${r.id}`)}
          columns={[
            { key: "name", header: t("Customer"), render: (r) => <span className="font-medium text-ink-900">{r.name}<span className="block text-xs font-normal text-ink-500">{r.code}</span></span> },
            { key: "phone", header: t("Phone") },
            { key: "customer_type", header: t("Type"), hideOnMobile: true, render: (r) => <span className="capitalize">{String(r.customer_type ?? "")}</span> },
            { key: "allow_credit", header: t("Credit"), hideOnMobile: true, render: (r) => (r.allow_credit ? <Badge tone="blue">{toNumber(r.credit_limit) > 0 ? t("Up to {credit_limit}", { credit_limit: money(r.credit_limit) }) : t("Allowed")}</Badge> : <span className="text-ink-400">{t("Cash only")}</span>) },
            { key: "user", header: t("Online"), hideOnMobile: true, render: (r) => (r.user ? <Badge tone={r.user.is_active ? "green" : "red"}>{r.user.is_active ? t("Account") : t("Disabled")}</Badge> : "—") },
            { key: "balance", header: t("Owes"), align: "right", render: (r) => (toNumber(r.balance) > 0 ? <span className="font-semibold text-ember-600">{money(r.balance)}</span> : "—") },
            ...(trashed ? [{ key: "_r", header: "", align: "right" as const, render: (r: Customer) => <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => restore.mutate(r.id)}>{t("Restore")}</Button> }] : []),
          ]}
          empty={{ title: trashed ? t("No deleted customers") : t("No customers found"), action: !trashed && can("customers.create") ? <Button onClick={() => setCreating(true)}>{t("Add a customer")}</Button> : undefined }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      <CustomerForm open={creating} onClose={() => setCreating(false)} onSaved={(c) => router.push(`/admin/customers/${c.id}`)} />
    </Guard>
  );
}
