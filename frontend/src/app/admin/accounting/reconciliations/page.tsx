"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { date, money, today } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, Textarea } from "@/components/ui";
import { AccountSelect } from "@/features/accounting/components/AccountSelect";
import type { Reconciliation } from "@/features/accounting/types";


export default function ReconciliationsPage() {
  const t = useT();
  const router = useRouter();
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ account_id: "" as number | "", statement_date: today(), statement_balance: "", notes: "" });
  const list = useApi<Paginated<Reconciliation>>("admin/accounting/reconciliations", { page });
  const start = useAction(() => api.post<ApiResponse<Reconciliation>>("admin/accounting/reconciliations", { ...f, statement_balance: Number(f.statement_balance), notes: f.notes || undefined }), {
    invalidate: [["admin/accounting/reconciliations"]], onSuccess: (r) => router.push(`/admin/accounting/reconciliations/${r.data.id}`),
  });
  const e = fieldErrors(start.error);

  return (
    <Guard perm="accounting.reconcile">
      <PageHeader title={t("Bank reconciliation")} description={t("Match the books to your bank or mobile money statement, item by item.")}
        actions={<Button icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t("Start reconciliation")}</Button>} />
      <Panel padded={false}>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/accounting/reconciliations/${r.id}`)}
          columns={[
            { key: "account", header: t("Account"), render: (r) => <span className="font-medium text-ink-900">{r.account?.code} {r.account?.name}</span> },
            { key: "statement_date", header: t("Statement date"), render: (r) => date(r.statement_date) },
            { key: "statement_balance", header: t("Statement balance"), align: "right", render: (r) => <span className="num">{money(r.statement_balance)}</span> },
            { key: "difference", header: t("Difference"), align: "right", render: (r) => <span className="num">{money(r.difference)}</span> },
            { key: "status", header: t("Status"), render: (r) => r.status === "completed" ? <Badge tone="green">{t("Reconciled")}</Badge> : <Badge tone="saffron">{t("In progress")}</Badge> },
          ]}
          empty={{ title: t("No reconciliations yet") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      <Modal open={open} onClose={() => setOpen(false)} title={t("Start reconciliation")}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t("Cancel")}</Button><Button loading={start.isPending} disabled={!f.account_id || f.statement_balance === ""} onClick={() => start.mutate(undefined)}>{t("Start")}</Button></>}>
        <div className="space-y-4">
          <Field label={t("Bank / mobile money account")} required error={e.account_id}>{(p) => <AccountSelect {...p} money value={f.account_id} onChange={(v) => setF({ ...f, account_id: v })} />}</Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t("Statement date")} required error={e.statement_date}>{(p) => <Input {...p} type="date" max={today()} value={f.statement_date} onChange={(ev) => setF({ ...f, statement_date: ev.target.value })} />}</Field>
            <Field label={t("Closing balance on the statement")} required error={e.statement_balance}>{(p) => <Input {...p} type="number" step="any" className="num text-right" value={f.statement_balance} onChange={(ev) => setF({ ...f, statement_balance: ev.target.value })} />}</Field>
          </div>
          <Field label={t("Notes")}>{(p) => <Textarea {...p} rows={2} value={f.notes} onChange={(ev) => setF({ ...f, notes: ev.target.value })} />}</Field>
        </div>
      </Modal>
    </Guard>
  );
}
