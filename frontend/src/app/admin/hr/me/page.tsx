"use client";

import { useState } from "react";
import { Clock, Download, LogIn, LogOut, Plus } from "lucide-react";
import { api, ApiError, download, type ApiResponse, type Paginated } from "@/lib/api";
import { date, money, toNumber, humanize, monthLabel, formatDate } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, DataTable, EmptyState, Field, Input, Modal, PageHeader, Panel, Select, Spinner, StatusBadge, Tabs, Textarea } from "@/components/ui";
import type { Employee, LeaveRequest, Payslip, SalaryAdvance } from "@/features/hr/types";

type Today = { status: string; clock_in: string | null; clock_out: string | null; hours_worked: string | null } | null;
type MyBalance = { leave_type_id: number; type: string; entitled: string; carried_forward: string; adjustment: string; taken: string; remaining: number; is_paid: boolean; requires_attachment: boolean };
const time = (v: string | null) => (v ? formatDate(new Date(v.includes("T") || v.includes(" ") ? v.replace(" ", "T") : `1970-01-01T${v}`), { hour: "2-digit", minute: "2-digit" }) : "—");

function ClockCard() {
  const t = useT();
  const q = useApi<ApiResponse<{ today: Today; records: Array<{ date: string; status: string; clock_in: string | null; clock_out: string | null; hours_worked: string | null }> }>>("admin/hr/me/attendance");
  const inv = [["admin/hr/me/attendance"]];
  const clockIn = useAction(() => api.post("admin/hr/me/attendance/clock-in", {}), { invalidate: inv });
  const clockOut = useAction(() => api.post("admin/hr/me/attendance/clock-out", {}), { invalidate: inv });
  const today = q.data?.data.today;
  const records = q.data?.data.records ?? [];
  return (
    <Panel title={t("Today")}>
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <span className="grid size-12 place-items-center rounded-full bg-brand-50 text-brand-700"><Clock className="size-6" /></span>
          <div>
            <p className="text-sm text-ink-500">{formatDate(new Date(), { weekday: "long", day: "numeric", month: "long" })}</p>
            <p className="font-medium">{today?.clock_in ? t("In at {in}{out}", { in: time(today.clock_in), out: today.clock_out ? ` · ${t("out at {time}", { time: time(today.clock_out) })}` : "" }) : t("Not clocked in yet")}</p>
          </div>
        </div>
        {!today?.clock_in && <Button size="lg" icon={<LogIn className="size-5" />} loading={clockIn.isPending} onClick={() => clockIn.mutate(undefined)}>{t("Clock in")}</Button>}
        {today?.clock_in && !today.clock_out && <Button size="lg" variant="secondary" icon={<LogOut className="size-5" />} loading={clockOut.isPending} onClick={() => clockOut.mutate(undefined)}>{t("Clock out")}</Button>}
        {today?.clock_out && <Badge tone="green">{t("{hours} hours worked", { hours: toNumber(today.hours_worked) })}</Badge>}
      </div>
      {records.length > 0 && (
        <div className="mt-4 flex flex-wrap gap-1" aria-label={t("This month")}>
          {records.map((r) => <span key={r.date} title={`${date(r.date)} — ${t(humanize(r.status))}`} className={`size-3 rounded-sm ${r.status === "absent" ? "bg-ember-500" : r.status === "late" ? "bg-saffron-500" : r.status === "on_leave" ? "bg-sky-500" : "bg-brand-500"}`} />)}
        </div>
      )}
    </Panel>
  );
}

function MyLeave() {
  const t = useT();
  const q = useApi<ApiResponse<{ balances: MyBalance[]; requests: LeaveRequest[] }>>("admin/hr/me/leave");
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ leave_type_id: "", start_date: "", end_date: "", half_day: false, reason: "", attachment: null as File | null });
  const inv = [["admin/hr/me/leave"]];
  const apply = useAction(() => {
    const fd = new FormData();
    Object.entries(f).forEach(([k, v]) => { if (v !== null && v !== "") fd.append(k, typeof v === "boolean" ? (v ? "1" : "0") : v); });
    return api.post("admin/hr/me/leave", fd);
  }, { invalidate: inv, onSuccess: () => { setOpen(false); setF({ leave_type_id: "", start_date: "", end_date: "", half_day: false, reason: "", attachment: null }); } });
  const withdraw = useAction((id: number) => api.post(`admin/hr/me/leave/${id}/cancel`, {}), { invalidate: inv });
  const e = fieldErrors(apply.error);
  const chosen = q.data?.data.balances.find((b) => String(b.leave_type_id) === f.leave_type_id);
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {q.data?.data.balances.map((b) => (
          <div key={b.leave_type_id} className="rounded-[var(--radius-panel)] bg-surface px-4 py-3 ring-1 ring-ink-200/70">
            <p className="text-sm text-ink-500">{b.type}</p>
            <p className="num text-2xl font-semibold">{b.remaining}<span className="text-sm font-normal text-ink-400"> / {toNumber(b.entitled) + toNumber(b.carried_forward) + toNumber(b.adjustment)}</span></p>
          </div>
        ))}
      </div>
      <Panel padded={false} title={t("My leave requests")} actions={<Button size="sm" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t("Apply for leave")}</Button>}>
        <DataTable rows={q.data?.data.requests} loading={q.isLoading} rowKey={(r) => r.id}
          columns={[
            { key: "type", header: t("Type"), render: (r) => <span className="font-medium">{r.leave_type?.name}<span className="block text-xs font-normal text-ink-500">{r.number}</span></span> },
            { key: "dates", header: t("Dates"), render: (r) => `${date(r.start_date)} – ${date(r.end_date)}` },
            { key: "days", header: t("Days"), align: "right", render: (r) => <span className="num">{toNumber(r.days)}</span> },
            { key: "status", header: t("Status"), render: (r) => <span><StatusBadge status={r.status} />{r.decision_note && <span className="block text-xs text-ink-500">{r.decision_note}</span>}</span> },
            { key: "_a", header: "", align: "right", render: (r) => (r.status === "pending" || (r.status === "approved" && new Date(r.start_date) > new Date())) && <Button size="sm" variant="ghost" onClick={() => confirm(t("Withdraw this request?")) && withdraw.mutate(r.id)}>{t("Withdraw")}</Button> },
          ]}
          empty={{ title: t("You have not applied for leave yet") }} />
      </Panel>
      <Modal open={open} onClose={() => setOpen(false)} title={t("Apply for leave")} description={t("Sundays and public holidays are not counted.")}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t("Cancel")}</Button><Button loading={apply.isPending} onClick={() => apply.mutate(undefined)}>{t("Send request")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Leave type")} required error={e.leave_type_id} hint={chosen ? t("{days} day(s) left", { days: chosen.remaining }) : undefined}>{(p) => <Select {...p} value={f.leave_type_id} onChange={(ev) => setF({ ...f, leave_type_id: ev.target.value })}><option value="">{t("Choose…")}</option>{q.data?.data.balances.map((b) => <option key={b.leave_type_id} value={b.leave_type_id}>{b.type}</option>)}</Select>}</Field>
          <div className="flex items-end pb-2"><Checkbox label={t("Half day")} checked={f.half_day} onChange={(ev) => setF({ ...f, half_day: ev.target.checked })} /></div>
          <Field label={t("From")} required error={e.start_date}>{(p) => <Input {...p} type="date" value={f.start_date} onChange={(ev) => setF({ ...f, start_date: ev.target.value, end_date: f.end_date || ev.target.value })} />}</Field>
          <Field label={t("To")} required error={e.end_date}>{(p) => <Input {...p} type="date" min={f.start_date} value={f.end_date} onChange={(ev) => setF({ ...f, end_date: ev.target.value })} />}</Field>
          <Field label={t("Reason")} error={e.reason} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={f.reason} onChange={(ev) => setF({ ...f, reason: ev.target.value })} />}</Field>
          {chosen?.requires_attachment && <Field label={t("Attachment")} required error={e.attachment} className="sm:col-span-2">{(p) => <Input {...p} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" className="py-1.5" onChange={(ev) => setF({ ...f, attachment: ev.target.files?.[0] ?? null })} />}</Field>}
        </div>
      </Modal>
    </div>
  );
}

function MyPay() {
  const t = useT();
  const slips = useApi<Paginated<Payslip>>("admin/hr/me/payslips");
  const adv = useApi<ApiResponse<SalaryAdvance[]>>("admin/hr/me/advances");
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ amount: "", installments: "1", reason: "" });
  const req = useAction(() => api.post("admin/hr/me/advances", f), { invalidate: [["admin/hr/me/advances"]], onSuccess: () => { setOpen(false); setF({ amount: "", installments: "1", reason: "" }); } });
  const e = fieldErrors(req.error);
  return (
    <div className="space-y-5">
      <Panel padded={false} title={t("My payslips")}>
        <DataTable rows={slips.data?.data} loading={slips.isLoading} rowKey={(s) => s.id}
          columns={[
            { key: "period", header: t("Month"), render: (s) => <span className="font-medium">{s.run ? monthLabel(s.run.period) : ""}</span> },
            { key: "gross", header: t("Gross"), align: "right", render: (s) => <span className="num">{money(s.gross)}</span> },
            { key: "ded", header: t("Deductions"), align: "right", hideOnMobile: true, render: (s) => <span className="num">{money(Number(s.gross) - Number(s.net))}</span> },
            { key: "net", header: t("Net pay"), align: "right", render: (s) => <span className="num font-semibold">{money(s.net)}</span> },
            { key: "status", header: t("Status"), render: (s) => <StatusBadge status={s.run?.status === "paid" ? "paid" : "approved"} /> },
            { key: "_pdf", header: "", align: "right", render: (s) => <Button size="sm" variant="ghost" icon={<Download className="size-4" />} onClick={() => download(`admin/hr/me/payslips/${s.id}/pdf`)}>PDF</Button> },
          ]}
          empty={{ title: t("No payslips yet") }} />
      </Panel>
      <Panel padded={false} title={t("My salary advances")} actions={<Button size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t("Request advance")}</Button>}>
        <DataTable rows={adv.data?.data} loading={adv.isLoading} rowKey={(a) => a.id}
          columns={[
            { key: "number", header: t("Number"), render: (a) => <span className="font-medium">{a.number}<span className="block text-xs font-normal text-ink-500">{date(a.created_at)}</span></span> },
            { key: "amount", header: t("Amount"), align: "right", render: (a) => <span className="num">{money(a.amount)}</span> },
            { key: "outstanding", header: t("Still owed"), align: "right", render: (a) => <span className="num">{money(a.outstanding)}</span> },
            { key: "status", header: t("Status"), render: (a) => <StatusBadge status={a.status} /> },
          ]}
          empty={{ title: t("No advances") }} />
      </Panel>
      <Modal open={open} onClose={() => setOpen(false)} title={t("Request a salary advance")}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t("Cancel")}</Button><Button loading={req.isPending} onClick={() => req.mutate(undefined)}>{t("Send request")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Amount")} required error={e.amount}>{(p) => <Input {...p} type="number" min={0} value={f.amount} onChange={(ev) => setF({ ...f, amount: ev.target.value })} />}</Field>
          <Field label={t("Repay over (months)")}>{(p) => <Select {...p} value={f.installments} onChange={(ev) => setF({ ...f, installments: ev.target.value })}>{Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}</Select>}</Field>
          <Field label={t("Reason")} required error={e.reason} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={f.reason} onChange={(ev) => setF({ ...f, reason: ev.target.value })} />}</Field>
        </div>
      </Modal>
    </div>
  );
}

export default function MyHrPage() {
  const t = useT();
  const [tab, setTab] = useState<"overview" | "leave" | "pay">("overview");
  const me = useApi<ApiResponse<Employee>>("admin/hr/me");
  const notLinked = me.error instanceof ApiError && me.error.status === 404;

  return (
    <Guard perm="hr.self_service">
      <PageHeader title={t("My HR")} description={me.data ? `${me.data.data.full_name} · ${me.data.data.employee_no}${me.data.data.position ? ` · ${me.data.data.position.title}` : ""}` : undefined} />
      {me.isLoading ? <Spinner /> : notLinked ? (
        <Panel><EmptyState title={t("Your login is not linked to an employee record")} message={t("Ask HR to link your account on your employee profile.")} /></Panel>
      ) : (
        <>
          <div className="mb-4"><Tabs value={tab} onChange={setTab} tabs={[{ value: "overview", label: t("Overview") }, { value: "leave", label: t("Leave") }, { value: "pay", label: t("Pay") }]} /></div>
          {tab === "overview" && me.data && (
            <div className="grid gap-5 lg:grid-cols-2">
              <ClockCard />
              <Panel title={t("My details")}>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                  {([["Department", me.data.data.department?.name], ["Branch", me.data.data.branch?.name], ["Start date", date(me.data.data.hire_date)], ["Employment type", t(humanize(me.data.data.employment_type))], ["Phone", me.data.data.phone], ["Reports to", me.data.data.manager ? `${me.data.data.manager.first_name} ${me.data.data.manager.last_name}` : null]] as Array<[string, string | null | undefined]>).map(([k, v]) => (
                    <div key={k}><dt className="text-xs text-ink-500">{t(k)}</dt><dd>{v || "—"}</dd></div>
                  ))}
                </dl>
                <p className="mt-4 text-xs text-ink-500">{t("To change your details, speak to HR.")}</p>
              </Panel>
            </div>
          )}
          {tab === "leave" && <MyLeave />}
          {tab === "pay" && <MyPay />}
        </>
      )}
    </Guard>
  );
}
