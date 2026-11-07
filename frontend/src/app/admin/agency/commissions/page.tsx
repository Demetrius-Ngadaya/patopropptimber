"use client";

import { useState } from "react";
import { Plus, Trash2 } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, monthLabel, today } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, Select, Stat } from "@/components/ui";
import { AgencyNav, ProviderDot } from "@/features/agency/AgencyNav";
import type { AgencyAccount, Commission } from "@/features/agency/types";

const lastMonth = () => {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
};

/** Commissions the providers pay, usually at the end of the month or early the next one. */
export default function CommissionsPage() {
  const t = useT();
  const { can } = useAuth();
  const [period, setPeriod] = useState("");
  const [accountId, setAccountId] = useState("");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Commission | "new" | null>(null);
  const [deleting, setDeleting] = useState<Commission | null>(null);
  const list = useApi<Paginated<Commission> & { total: number }>("admin/agency/commissions", { period, agency_account_id: accountId, page });
  const accounts = useApi<ApiResponse<{ accounts: AgencyAccount[] }>>("admin/agency/accounts", { all: 1 });
  const remove = useAction((id: number) => api.del(`admin/agency/commissions/${id}`), { invalidate: [["admin/agency/commissions"]], onSuccess: () => setDeleting(null) });

  return (
    <Guard perm={["agency.view", "agency.commissions"]}>
      <PageHeader
        title={t("Commissions")}
        description={t("What each provider paid for the month's agency work. Record it when it arrives, usually at month end or early next month.")}
        actions={can("agency.commissions") && <Button icon={<Plus className="size-4" />} onClick={() => setEditing("new")}>{t("Record commission")}</Button>}
      />
      <AgencyNav />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={period ? t("Total for {month}", { month: monthLabel(period) }) : t("Total shown")} value={money(list.data?.total ?? 0)} tone="brand" />
      </div>
      <Panel padded={false}>
        <div className="flex flex-wrap gap-3 border-b border-ink-100 p-3">
          <Field label={t("Month")}>{(p) => <Input {...p} type="month" value={period} onChange={(e) => { setPeriod(e.target.value); setPage(1); }} />}</Field>
          <Field label={t("Line")}>{(p) => (
            <Select {...p} value={accountId} onChange={(e) => { setAccountId(e.target.value); setPage(1); }}>
              <option value="">{t("All lines")}</option>
              {accounts.data?.data.accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select>
          )}</Field>
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={can("agency.commissions") ? (r) => setEditing(r) : undefined}
          columns={[
            { key: "period", header: t("Month"), render: (r) => <span className="font-medium">{monthLabel(r.period)}</span> },
            { key: "account", header: t("Line"), render: (r) => <span className="flex items-center gap-2"><ProviderDot provider={r.account?.provider ?? "other"} />{r.account?.name}</span> },
            { key: "amount", header: t("Commission"), align: "right", render: (r) => <span className="font-semibold">{money(r.amount)}</span> },
            { key: "received_on", header: t("Received on"), render: (r) => date(r.received_on) },
            { key: "reference", header: t("Reference"), hideOnMobile: true },
            { key: "branch", header: t("Branch"), hideOnMobile: true, render: (r) => r.branch?.name ?? "—" },
            ...(can("agency.commissions") ? [{ key: "_del", header: "", align: "right" as const, render: (r: Commission) => <Button size="sm" variant="ghost" aria-label={t("Delete")} onClick={(e) => { e.stopPropagation(); setDeleting(r); }}><Trash2 className="size-4" /></Button> }] : []),
          ]}
          empty={{ title: t("No commissions recorded") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      {editing && <CommissionDialog commission={editing === "new" ? null : editing} accounts={accounts.data?.data.accounts ?? []} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={Boolean(deleting)} onClose={() => setDeleting(null)} title={t("Delete this commission?")} message={deleting ? `${deleting.account?.name} · ${monthLabel(deleting.period)} · ${money(deleting.amount)}` : ""}
        confirmLabel={t("Delete")} loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting.id)} />
    </Guard>
  );
}

function CommissionDialog({ commission, accounts, onClose }: { commission: Commission | null; accounts: AgencyAccount[]; onClose: () => void }) {
  const t = useT();
  const [f, setF] = useState({
    agency_account_id: String(commission?.agency_account_id ?? accounts[0]?.id ?? ""), period: commission?.period ?? lastMonth(),
    amount: commission ? String(Number(commission.amount)) : "", received_on: commission?.received_on?.slice(0, 10) ?? today(),
    reference: commission?.reference ?? "", notes: commission?.notes ?? "",
  });
  const save = useAction(() => {
    const body = { ...f, agency_account_id: Number(f.agency_account_id), amount: Number(f.amount), reference: f.reference || null, notes: f.notes || null };
    return commission ? api.put(`admin/agency/commissions/${commission.id}`, body) : api.post("admin/agency/commissions", body);
  }, { invalidate: [["admin/agency/commissions"], ["admin/agency/summary"]], onSuccess: onClose });
  const e = fieldErrors(save.error);
  return (
    <Modal open onClose={onClose} title={commission ? t("Edit commission") : t("Record commission")}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("Line")} required error={e.agency_account_id}>{(p) => <Select {...p} value={f.agency_account_id} onChange={(ev) => setF({ ...f, agency_account_id: ev.target.value })}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select>}</Field>
        <Field label={t("Month the commission is for")} required error={e.period}>{(p) => <Input {...p} type="month" value={f.period} onChange={(ev) => setF({ ...f, period: ev.target.value })} />}</Field>
        <Field label={t("Amount")} required error={e.amount}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.amount} onChange={(ev) => setF({ ...f, amount: ev.target.value })} />}</Field>
        <Field label={t("Received on")} required error={e.received_on}>{(p) => <Input {...p} type="date" max={today()} value={f.received_on} onChange={(ev) => setF({ ...f, received_on: ev.target.value })} />}</Field>
        <Field label={t("Reference")} error={e.reference}>{(p) => <Input {...p} value={f.reference} onChange={(ev) => setF({ ...f, reference: ev.target.value })} />}</Field>
        <Field label={t("Notes")} error={e.notes}>{(p) => <Input {...p} value={f.notes} onChange={(ev) => setF({ ...f, notes: ev.target.value })} />}</Field>
      </div>
    </Modal>
  );
}
