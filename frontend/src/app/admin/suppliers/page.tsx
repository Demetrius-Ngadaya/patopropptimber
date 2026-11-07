"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus, RotateCcw } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, toNumber } from "@/lib/format";
import { useAction, useApi, useDebounced } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Button, Checkbox, DataTable, PageHeader, Pagination, Panel, SearchInput, Stat } from "@/components/ui";
import { SupplierForm, type Supplier } from "@/features/purchasing/components/SupplierForm";
import { useT } from "@/lib/i18n";

export default function SuppliersPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [search, setSearch] = useState("");
  const [owed, setOwed] = useState(false);
  const [trashed, setTrashed] = useState(false);
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const q = useDebounced(search);
  const list = useApi<Paginated<Supplier> & { summary: { total_owed: number } }>("admin/suppliers", { search: q, page, filter: owed ? "owed" : undefined, trashed: trashed ? "only" : undefined });
  const restore = useAction((id: number) => api.post(`admin/suppliers/${id}/restore`), { invalidate: [["admin/suppliers"]] });

  return (
    <Guard perm="suppliers.view">
      <PageHeader title={t("Suppliers")} description={t("Who you buy from and how much you owe them.")} actions={can("suppliers.create") && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t("New supplier")}</Button>} />
      {list.data?.summary && <div className="mb-5 max-w-xs"><Stat label={t("You owe suppliers")} value={money(list.data.summary.total_owed)} tone="saffron" /></div>}
      <Panel padded={false}>
        <div className="flex flex-wrap items-center gap-4 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={setSearch} placeholder={t("Name, phone or code")} className="w-full max-w-sm" />
          <Checkbox label={t("Only suppliers we owe")} checked={owed} onChange={(e) => setOwed(e.target.checked)} />
          {can("suppliers.restore") && <Checkbox label={t("Show deleted")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} />}
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={trashed ? undefined : (r) => router.push(`/admin/suppliers/${r.id}`)}
          columns={[
            { key: "name", header: t("Supplier"), render: (r) => <span className="font-medium">{r.name}<span className="block text-xs font-normal text-ink-500">{r.code}</span></span> },
            { key: "contact_person", header: t("Contact"), hideOnMobile: true },
            { key: "phone", header: t("Phone") },
            { key: "payment_terms_days", header: t("Terms"), hideOnMobile: true, render: (r) => `${r.payment_terms_days} days` },
            { key: "balance", header: t("We owe"), align: "right", render: (r) => (toNumber(r.balance) > 0 ? <span className="font-semibold text-ember-600">{money(r.balance)}</span> : toNumber(r.balance) < 0 ? <span className="text-brand-700">{t("credit {value}", { value: money(-toNumber(r.balance)) })}</span> : "—") },
            ...(trashed ? [{ key: "_r", header: "", align: "right" as const, render: (r: Supplier) => <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => restore.mutate(r.id)}>{t("Restore")}</Button> }] : []),
          ]}
          empty={{ title: t("No suppliers found") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      {creating && <SupplierForm open={creating} onClose={() => setCreating(false)} />}
    </Guard>
  );
}
