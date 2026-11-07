"use client";

import { useState } from "react";
import { Check, Paperclip, Plus, SlidersHorizontal, X } from "lucide-react";
import { api, download, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge, Button, Checkbox, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, Select, StatusBadge, Tabs, Textarea } from "@/components/ui";
import { EmployeeSelect } from "@/features/hr/components/EmployeeSelect";
import { employeeName, type LeaveRequest } from "@/features/hr/types";

type LeaveType = { id: number; name: string; requires_attachment: boolean };
type BalanceRow = { employee_id: number; employee: string; balances: Array<{ id: number; leave_type_id: number; type: string; entitled: string; carried_forward: string; adjustment: string; taken: string; remaining: number }> };

function NewRequest({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const types = useApi<Paginated<LeaveType>>(open ? "admin/hr/leave-types" : null, { per_page: 100 });
  const [f, setF] = useState({ employee_id: "", leave_type_id: "", start_date: "", end_date: "", half_day: false, reason: "", attachment: null as File | null });
  const save = useAction(() => {
    const fd = new FormData();
    Object.entries(f).forEach(([k, v]) => {
      if (v === null || v === "") return;
      fd.append(k, typeof v === "boolean" ? (v ? "1" : "0") : v);
    });
    return api.post("admin/hr/leave", fd);
  }, { invalidate: [["admin/hr/leave"]], onSuccess: () => { onClose(); setF({ employee_id: "", leave_type_id: "", start_date: "", end_date: "", half_day: false, reason: "", attachment: null }); } });
  const e = fieldErrors(save.error);
  return (
    <Modal open={open} onClose={onClose} title={t("Record leave")} description={t("For leave agreed in person. It is approved straight away and the days come off the balance.")}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Employee")} required error={e.employee_id} className="sm:col-span-2">{(p) => <EmployeeSelect {...p} value={f.employee_id} onChange={(v) => setF({ ...f, employee_id: v })} />}</Field>
        <Field label={t("Leave type")} required error={e.leave_type_id}>{(p) => <Select {...p} value={f.leave_type_id} onChange={(ev) => setF({ ...f, leave_type_id: ev.target.value })}><option value="">{t("Choose…")}</option>{types.data?.data.map((ty) => <option key={ty.id} value={ty.id}>{ty.name}</option>)}</Select>}</Field>
        <div className="flex items-end pb-2"><Checkbox label={t("Half day")} checked={f.half_day} onChange={(ev) => setF({ ...f, half_day: ev.target.checked })} /></div>
        <Field label={t("From")} required error={e.start_date}>{(p) => <Input {...p} type="date" value={f.start_date} onChange={(ev) => setF({ ...f, start_date: ev.target.value, end_date: f.end_date || ev.target.value })} />}</Field>
        <Field label={t("To")} required error={e.end_date}>{(p) => <Input {...p} type="date" value={f.end_date} min={f.start_date} onChange={(ev) => setF({ ...f, end_date: ev.target.value })} />}</Field>
        <Field label={t("Reason")} error={e.reason} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={f.reason} onChange={(ev) => setF({ ...f, reason: ev.target.value })} />}</Field>
        <Field label={t("Attachment")} error={e.attachment} hint={t("Sick note or other proof (PDF or image).")} className="sm:col-span-2">{(p) => <Input {...p} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" className="py-1.5" onChange={(ev) => setF({ ...f, attachment: ev.target.files?.[0] ?? null })} />}</Field>
      </div>
    </Modal>
  );
}

function Requests() {
  const t = useT();
  const { can } = useAuth();
  const [status, setStatus] = useState("pending");
  const [employee, setEmployee] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [rejecting, setRejecting] = useState<LeaveRequest | null>(null);
  const [note, setNote] = useState("");
  const list = useApi<Paginated<LeaveRequest>>("admin/hr/leave", { status: status || undefined, employee_id: employee || undefined, page });
  const inv = [["admin/hr/leave"]];
  const approve = useAction((id: number) => api.post(`admin/hr/leave/${id}/approve`, {}), { invalidate: inv });
  const reject = useAction(() => api.post(`admin/hr/leave/${rejecting!.id}/reject`, { note }), { invalidate: inv, onSuccess: () => { setRejecting(null); setNote(""); } });
  const cancel = useAction((id: number) => api.post(`admin/hr/leave/${id}/cancel`, {}), { invalidate: inv });

  return (
    <Panel padded={false}>
      <div className="px-3 pt-2"><Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[{ value: "pending", label: t("Waiting") }, { value: "approved", label: t("Approved") }, { value: "rejected", label: t("Rejected") }, { value: "cancelled", label: t("Cancelled") }, { value: "", label: t("All") }]} /></div>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 p-3">
        <EmployeeSelect allowAll value={employee} onChange={(v) => { setEmployee(v); setPage(1); }} className="w-full max-w-xs" aria-label={t("Employee")} />
        {can("leave.approve") && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t("Record leave")}</Button>}
      </div>
      <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id}
        columns={[
          { key: "employee", header: t("Employee"), render: (r) => <span className="font-medium">{employeeName(r.employee)}<span className="block text-xs font-normal text-ink-500">{r.number}</span></span> },
          { key: "type", header: t("Type"), render: (r) => r.leave_type?.name },
          { key: "dates", header: t("Dates"), render: (r) => <span>{date(r.start_date)}{r.end_date !== r.start_date && <> – {date(r.end_date)}</>}<span className="block text-xs text-ink-500">{t("{days} working day(s)", { days: toNumber(r.days) })}</span></span> },
          { key: "reason", header: t("Reason"), hideOnMobile: true, render: (r) => <span className="line-clamp-2 max-w-xs text-ink-600">{r.reason ?? "—"}{r.decision_note && <span className="block text-xs text-ink-500">{t("Note")}: {r.decision_note}</span>}</span> },
          { key: "status", header: t("Status"), render: (r) => <StatusBadge status={r.status} /> },
          { key: "_a", header: "", align: "right", render: (r) => (
            <span className="flex justify-end gap-1">
              {r.has_attachment && <Button size="sm" variant="ghost" aria-label={t("Attachment")} onClick={() => download(`admin/hr/leave/${r.id}/attachment`)}><Paperclip className="size-4" /></Button>}
              {can("leave.approve") && r.status === "pending" && <>
                <Button size="sm" variant="secondary" icon={<Check className="size-3.5" />} loading={approve.isPending && approve.variables === r.id} onClick={() => approve.mutate(r.id)}>{t("Approve")}</Button>
                <Button size="sm" variant="ghost" icon={<X className="size-3.5" />} onClick={() => setRejecting(r)}>{t("Reject")}</Button>
              </>}
              {can("leave.approve") && r.status === "approved" && new Date(r.end_date) >= new Date(new Date().toDateString()) &&
                <Button size="sm" variant="ghost" onClick={() => confirm(t("Cancel this leave and give the days back?")) && cancel.mutate(r.id)}>{t("Cancel")}</Button>}
            </span>
          ) },
        ]}
        empty={{ title: status === "pending" ? t("No leave requests waiting") : t("No leave requests found") }} />
      <Pagination meta={list.data?.meta} onPage={setPage} />
      <NewRequest open={creating} onClose={() => setCreating(false)} />
      <Modal open={!!rejecting} onClose={() => setRejecting(null)} title={t("Reject leave request")}
        footer={<><Button variant="secondary" onClick={() => setRejecting(null)}>{t("Cancel")}</Button><Button variant="danger" loading={reject.isPending} disabled={note.trim().length < 3} onClick={() => reject.mutate(undefined)}>{t("Reject")}</Button></>}>
        <Field label={t("Reason (the employee will see this)")} required>{(p) => <Textarea {...p} rows={3} value={note} onChange={(ev) => setNote(ev.target.value)} />}</Field>
      </Modal>
    </Panel>
  );
}

function Balances() {
  const t = useT();
  const { can } = useAuth();
  const [year, setYear] = useState(new Date().getFullYear());
  const q = useApi<ApiResponse<BalanceRow[]>>("admin/hr/leave/balances", { year });
  const [adjusting, setAdjusting] = useState<{ row: BalanceRow; b: BalanceRow["balances"][number] } | null>(null);
  const [adj, setAdj] = useState({ adjustment: "0", reason: "" });
  const save = useAction(() => api.put(`admin/hr/leave/balances/${adjusting!.b.id}`, adj), { invalidate: [["admin/hr/leave/balances"]], onSuccess: () => setAdjusting(null) });
  const types = Array.from(new Set((q.data?.data ?? []).flatMap((r) => r.balances.map((b) => b.type))));

  return (
    <Panel padded={false} title={<span className="flex items-center gap-3">{t("Leave balances")}<Select value={year} onChange={(e) => setYear(Number(e.target.value))} className="h-9 w-28">{[0, 1, 2].map((d) => <option key={d} value={new Date().getFullYear() - d}>{new Date().getFullYear() - d}</option>)}</Select></span>}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-500"><tr><th className="px-4 py-2">{t("Employee")}</th>{types.map((ty) => <th key={ty} className="px-3 py-2 text-right">{ty}</th>)}</tr></thead>
          <tbody className="divide-y divide-ink-100">
            {(q.data?.data ?? []).map((row) => (
              <tr key={row.employee_id}>
                <td className="px-4 py-2 font-medium">{row.employee}</td>
                {types.map((ty) => {
                  const b = row.balances.find((x) => x.type === ty);
                  return (
                    <td key={ty} className="px-3 py-2 text-right">
                      {b ? (
                        <button type="button" disabled={!can("leave.manage")} onClick={() => { setAdj({ adjustment: String(toNumber(b.adjustment)), reason: "" }); setAdjusting({ row, b }); }}
                          className="num rounded px-1 hover:bg-ink-100 disabled:hover:bg-transparent" title={t("Entitled {e}, carried {c}, adjusted {a}, taken {tk}", { e: toNumber(b.entitled), c: toNumber(b.carried_forward), a: toNumber(b.adjustment), tk: toNumber(b.taken) })}>
                          <span className={b.remaining < 0 ? "font-semibold text-ember-600" : "font-semibold"}>{b.remaining}</span>
                          <span className="text-xs text-ink-400"> / {toNumber(b.entitled) + toNumber(b.carried_forward) + toNumber(b.adjustment)}</span>
                        </button>
                      ) : <span className="text-ink-300">—</span>}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">{t("Days left / days available. Click a figure to adjust it.")}</p>
      <Modal open={!!adjusting} onClose={() => setAdjusting(null)} title={adjusting ? t("Adjust {type} for {name}", { type: adjusting.b.type, name: adjusting.row.employee }) : ""}
        footer={<><Button variant="secondary" onClick={() => setAdjusting(null)}>{t("Cancel")}</Button><Button icon={<SlidersHorizontal className="size-4" />} loading={save.isPending} disabled={adj.reason.trim().length < 3} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Adjustment (days)")} hint={t("Positive adds days, negative removes them.")}>{(p) => <Input {...p} type="number" step="0.5" value={adj.adjustment} onChange={(e) => setAdj({ ...adj, adjustment: e.target.value })} />}</Field>
          <Field label={t("Reason")} required className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={adj.reason} onChange={(e) => setAdj({ ...adj, reason: e.target.value })} />}</Field>
        </div>
      </Modal>
    </Panel>
  );
}

const LEAVE_PERMS = { create: "leave.manage", update: "leave.manage", delete: "leave.manage", restore: "leave.manage" };

export default function LeavePage() {
  const t = useT();
  const { can } = useAuth();
  const [tab, setTab] = useState<"requests" | "balances" | "types" | "holidays">("requests");
  const yes = (v: unknown) => (v ? <Badge tone="green">{t("Yes")}</Badge> : <Badge>{t("No")}</Badge>);
  return (
    <Guard perm="leave.view">
      {(tab === "requests" || tab === "balances") && <PageHeader title={t("Leave")} description={t("Annual, sick, maternity and other leave. Approved leave marks attendance and is paid or unpaid in payroll by its type.")} />}
      <div className="mb-4"><Tabs value={tab} onChange={setTab} tabs={[{ value: "requests", label: t("Requests") }, { value: "balances", label: t("Balances") }, ...(can("leave.manage") ? [{ value: "types" as const, label: t("Leave types") }, { value: "holidays" as const, label: t("Public holidays") }] : [])]} /></div>
      {tab === "requests" && <Requests />}
      {tab === "balances" && <Balances />}
      {tab === "types" && (
        <ResourcePage title={t("Leave types")} description={t("Days per year are pro-rated for staff who join mid-year. Unused days carry forward up to the limit.")} endpoint="admin/hr/leave-types" perm="leave" perms={LEAVE_PERMS} noun="leave type"
          columns={[
            { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{String(r.name)}</span> },
            { key: "days_per_year", header: t("Days per year"), align: "right", render: (r) => <span className="num">{toNumber(r.days_per_year)}</span> },
            { key: "max_carry_forward", header: t("Max carry forward"), align: "right", render: (r) => <span className="num">{toNumber(r.max_carry_forward)}</span> },
            { key: "is_paid", header: t("Paid"), render: (r) => yes(r.is_paid) },
            { key: "gender", header: t("Only for"), render: (r) => (r.gender ? t(r.gender === "female" ? "Female" : "Male") : t("Everyone")) },
          ]}
          fields={[
            { name: "name", label: t("Name"), required: true },
            { name: "code", label: t("Code"), required: true },
            { name: "days_per_year", label: t("Days per year"), type: "number", required: true },
            { name: "max_carry_forward", label: t("Max carry forward"), type: "number" },
            { name: "gender", label: t("Only for"), type: "select", options: [{ value: "female", label: t("Female") }, { value: "male", label: t("Male") }] },
            { name: "is_paid", label: t("Paid leave"), type: "switch" },
            { name: "requires_attachment", label: t("Needs an attachment (e.g. sick note)"), type: "switch" },
            { name: "is_active", label: t("Active"), type: "switch" },
          ]}
          defaults={{ name: "", code: "", days_per_year: 0, max_carry_forward: 0, gender: null, is_paid: true, requires_attachment: false, is_active: true }} />
      )}
      {tab === "holidays" && (
        <ResourcePage title={t("Public holidays")} description={t("Holidays are not counted as leave days. Recurring holidays repeat every year on the same date.")} endpoint="admin/hr/holidays" perm="leave" perms={LEAVE_PERMS} noun="holiday"
          columns={[
            { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{String(r.name)}</span> },
            { key: "date", header: t("Date"), render: (r) => date(String(r.date)) },
            { key: "recurring", header: t("Every year"), render: (r) => yes(r.recurring) },
          ]}
          fields={[
            { name: "name", label: t("Name"), required: true },
            { name: "date", label: t("Date"), type: "date", required: true },
            { name: "recurring", label: t("Repeats every year"), type: "switch" },
          ]}
          toForm={(r) => ({ name: r.name, date: String(r.date).slice(0, 10), recurring: r.recurring })}
          defaults={{ name: "", date: "", recurring: true }} />
      )}
    </Guard>
  );
}
