"use client";

import { useState } from "react";
import { Plus } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Panel, Select, Switch } from "@/components/ui";
import { AgencyNav, ProviderDot } from "@/features/agency/AgencyNav";
import { KIND_LABELS, type AgencyAccount, type Provider } from "@/features/agency/types";

/** The agency lines the business runs: mobile money and bank agencies. */
export default function AgencyAccountsPage() {
  const t = useT();
  const q = useApi<ApiResponse<{ accounts: AgencyAccount[]; providers: Provider[] }>>("admin/agency/accounts", { all: 1 });
  const [editing, setEditing] = useState<AgencyAccount | "new" | null>(null);

  return (
    <Guard perm="agency.manage">
      <PageHeader title={t("Agency lines")} description={t("Each mobile money or bank agency you run. Its float is counted every morning and evening.")}
        actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing("new")}>{t("Add line")}</Button>} />
      <AgencyNav />
      <Panel padded={false}>
        <DataTable
          rows={q.data?.data.accounts}
          loading={q.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => setEditing(r)}
          columns={[
            { key: "name", header: t("Line"), render: (r) => <span className="flex items-center gap-2 font-medium"><ProviderDot provider={r.provider} />{r.name}</span> },
            { key: "kind", header: t("Type"), render: (r) => t(KIND_LABELS[r.kind] ?? r.kind) },
            { key: "agent_number", header: t("Agent / till number") },
            { key: "branch", header: t("Branch"), hideOnMobile: true, render: (r) => r.branch?.name ?? t("All branches") },
            { key: "is_active", header: t("State"), render: (r) => <Badge tone={r.is_active ? "green" : "neutral"}>{r.is_active ? t("Active") : t("Inactive")}</Badge> },
          ]}
          empty={{ title: t("No agency lines yet"), action: <Button icon={<Plus className="size-4" />} onClick={() => setEditing("new")}>{t("Add line")}</Button> }}
        />
      </Panel>
      {editing && q.data && <AccountDialog account={editing === "new" ? null : editing} providers={q.data.data.providers} onClose={() => setEditing(null)} />}
    </Guard>
  );
}

function AccountDialog({ account, providers, onClose }: { account: AgencyAccount | null; providers: Provider[]; onClose: () => void }) {
  const t = useT();
  const { branches = [] } = useLookups(["branches"]);
  const [f, setF] = useState({
    provider: account?.provider ?? "mpesa", name: account?.name ?? "M-Pesa", agent_number: account?.agent_number ?? "",
    branch_id: account?.branch_id ? String(account.branch_id) : "", is_active: account?.is_active ?? true, sort_order: String(account?.sort_order ?? 0),
  });
  const [removing, setRemoving] = useState(false);
  const save = useAction(() => {
    const body = { ...f, branch_id: f.branch_id ? Number(f.branch_id) : null, sort_order: Number(f.sort_order) || 0, agent_number: f.agent_number || null };
    return account ? api.put(`admin/agency/accounts/${account.id}`, body) : api.post("admin/agency/accounts", body);
  }, { invalidate: [["admin/agency/accounts"], ["admin/agency/today"]], onSuccess: onClose });
  const remove = useAction(() => api.del(`admin/agency/accounts/${account!.id}`), { invalidate: [["admin/agency/accounts"]], onSuccess: onClose });
  const e = fieldErrors(save.error);

  return (
    <Modal open onClose={onClose} title={account ? account.name : t("Add line")}
      footer={<>
        {account && <Button variant="ghost" className="mr-auto text-ember-600" onClick={() => setRemoving(true)}>{t("Remove")}</Button>}
        <Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button>
      </>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("Provider")} required error={e.provider}>{(p) => (
          <Select {...p} value={f.provider} onChange={(ev) => {
            const pr = providers.find((x) => x.key === ev.target.value);
            setF({ ...f, provider: ev.target.value, name: !account && pr && pr.key !== "other" ? pr.name : f.name });
          }}>{providers.map((x) => <option key={x.key} value={x.key}>{x.name}</option>)}</Select>
        )}</Field>
        <Field label={t("Name shown")} required error={e.name}>{(p) => <Input {...p} value={f.name} onChange={(ev) => setF({ ...f, name: ev.target.value })} />}</Field>
        <Field label={t("Agent / till number")} error={e.agent_number}>{(p) => <Input {...p} value={f.agent_number} onChange={(ev) => setF({ ...f, agent_number: ev.target.value })} />}</Field>
        <Field label={t("Branch")} error={e.branch_id}>{(p) => (
          <Select {...p} value={f.branch_id} onChange={(ev) => setF({ ...f, branch_id: ev.target.value })}>
            <option value="">{t("All branches")}</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select>
        )}</Field>
        <Field label={t("Order in the list")} error={e.sort_order}>{(p) => <Input {...p} type="number" min={0} value={f.sort_order} onChange={(ev) => setF({ ...f, sort_order: ev.target.value })} />}</Field>
        <div className="pt-6"><Switch label={t("Active")} checked={f.is_active} onChange={(v) => setF({ ...f, is_active: v })} /></div>
      </div>
      <ConfirmDialog open={removing} onClose={() => setRemoving(false)} title={t("Remove {name}?", { name: account?.name ?? "" })} message={t("It is no longer counted. Its past figures are kept.")}
        confirmLabel={t("Remove")} loading={remove.isPending} onConfirm={() => remove.mutate(undefined)} />
    </Modal>
  );
}
