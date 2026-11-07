"use client";

import { useState } from "react";
import { Lock, LockOpen, Plus } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Panel } from "@/components/ui";
import type { FiscalYear } from "@/features/accounting/types";

export default function YearsPage() {
  const t = useT();
  const { can } = useAuth();
  const q = useApi<ApiResponse<FiscalYear[]>>("admin/accounting/fiscal-years");
  const [creating, setCreating] = useState(false);
  const [closing, setClosing] = useState<FiscalYear | null>(null);
  const [reopening, setReopening] = useState<FiscalYear | null>(null);
  const y = new Date().getFullYear() + 1;
  const [form, setForm] = useState({ name: `FY ${y}`, start_date: `${y}-01-01`, end_date: `${y}-12-31` });
  const inv = [["admin/accounting/fiscal-years"]];
  const create = useAction(() => api.post("admin/accounting/fiscal-years", form), { invalidate: inv, onSuccess: () => setCreating(false) });
  const close = useAction((id: number) => api.post(`admin/accounting/fiscal-years/${id}/close`), { invalidate: inv, onSuccess: () => setClosing(null) });
  const reopen = useAction((v: { id: number; reason: string }) => api.post(`admin/accounting/fiscal-years/${v.id}/reopen`, { reason: v.reason }), { invalidate: inv, onSuccess: () => setReopening(null) });
  const e = fieldErrors(create.error);

  return (
    <Guard perm={["accounting.view", "accounting.close_year"]}>
      <PageHeader title={t("Financial years")} description={t("Closing a year moves its profit or loss into retained earnings and locks every date in it, so past figures cannot change.")}
        actions={can("accounting.close_year") && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t("New year")}</Button>} />
      <Panel padded={false}>
        <DataTable rows={q.data?.data} loading={q.isFetching} rowKey={(r) => r.id}
          columns={[
            { key: "name", header: t("Year"), render: (r) => <span className="font-medium text-ink-900">{r.name}</span> },
            { key: "period", header: t("Period"), render: (r) => `${date(r.start_date)} – ${date(r.end_date)}` },
            { key: "status", header: t("Status"), render: (r) => r.status === "closed" ? <Badge tone="neutral">{t("Closed")} {r.closed_at && `· ${dateTime(r.closed_at)}`}</Badge> : <Badge tone="green">{t("Open")}</Badge> },
            { key: "_a", header: "", align: "right", render: (r) => can("accounting.close_year") && (r.status === "open"
              ? <Button size="sm" variant="secondary" icon={<Lock className="size-3.5" />} onClick={() => setClosing(r)}>{t("Close year")}</Button>
              : <Button size="sm" variant="ghost" icon={<LockOpen className="size-3.5" />} onClick={() => setReopening(r)}>{t("Reopen")}</Button>) },
          ]}
          empty={{ title: t("No financial years yet") }} />
      </Panel>
      <Modal open={creating} onClose={() => setCreating(false)} title={t("New financial year")} size="sm"
        footer={<><Button variant="secondary" onClick={() => setCreating(false)}>{t("Cancel")}</Button><Button loading={create.isPending} onClick={() => create.mutate(undefined)}>{t("Create")}</Button></>}>
        <div className="space-y-4">
          <Field label={t("Name")} required error={e.name}>{(p) => <Input {...p} value={form.name} onChange={(ev) => setForm({ ...form, name: ev.target.value })} />}</Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t("Starts")} required error={e.start_date}>{(p) => <Input {...p} type="date" value={form.start_date} onChange={(ev) => setForm({ ...form, start_date: ev.target.value })} />}</Field>
            <Field label={t("Ends")} required error={e.end_date}>{(p) => <Input {...p} type="date" value={form.end_date} onChange={(ev) => setForm({ ...form, end_date: ev.target.value })} />}</Field>
          </div>
        </div>
      </Modal>
      <ConfirmDialog open={Boolean(closing)} onClose={() => setClosing(null)} tone="primary" loading={close.isPending} onConfirm={() => closing && close.mutate(closing.id)}
        title={t("Close {year}?", { year: closing?.name ?? "" })} message={t("Income and expense accounts are cleared into retained earnings and no entry can be dated in this year afterwards. You can reopen it if an auditor requires a correction.")} confirmLabel={t("Close year")} />
      <ConfirmDialog open={Boolean(reopening)} onClose={() => setReopening(null)} requireReason loading={reopen.isPending} onConfirm={(reason) => reopening && reopen.mutate({ id: reopening.id, reason })}
        title={t("Reopen {year}?", { year: reopening?.name ?? "" })} message={t("The closing entry is reversed and the year accepts entries again.")} confirmLabel={t("Reopen")} />
    </Guard>
  );
}
