"use client";

import { useEffect, useMemo, useState } from "react";
import { CheckCheck, Save } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, today } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, DataTable, Input, PageHeader, Panel, Select, Spinner, Tabs } from "@/components/ui";
import { useEmployees } from "@/features/hr/components/EmployeeSelect";

const STATUSES = ["present", "late", "absent", "half_day", "on_leave", "holiday", "off"] as const;
type Status = (typeof STATUSES)[number];
type Row = { employee_id: number; status: Status; clock_in: string; clock_out: string; notes: string };
type Record_ = { employee_id: number; status: Status; clock_in: string | null; clock_out: string | null; notes: string | null };
type Summary = { employee_id: number; employee: string; employee_no: string; days_present: number; days_late: number; days_absent: number; days_on_leave: number; hours: number; overtime: number };

const STATUS_STYLE: Record<Status, string> = {
  present: "bg-brand-600 text-white", late: "bg-saffron-500 text-white", absent: "bg-ember-600 text-white", half_day: "bg-saffron-300 text-ink-900",
  on_leave: "bg-sky-600 text-white", holiday: "bg-ink-500 text-white", off: "bg-ink-200 text-ink-700",
};
const STATUS_LABEL: Record<Status, string> = { present: "Present", late: "Late", absent: "Absent", half_day: "Half day", on_leave: "On leave", holiday: "Public holiday", off: "Day off" };
const hhmm = (v: string | null) => (v ? (v.length > 5 ? v.slice(11, 16) || v.slice(0, 5) : v) : "");

function Register() {
  const t = useT();
  const { can } = useAuth();
  const [day, setDay] = useState(today());
  const employees = useEmployees();
  const existing = useApi<Paginated<Record_>>("admin/hr/attendance", { date: day, per_page: 200 });
  const [rows, setRows] = useState<Record<number, Row>>({});

  useEffect(() => {
    if (!existing.data) return;
    const byId = new Map(existing.data.data.map((r) => [r.employee_id, r]));
    setRows(Object.fromEntries(employees.map((e) => {
      const r = byId.get(e.id);
      return [e.id, { employee_id: e.id, status: r?.status ?? (e.status === "on_leave" ? "on_leave" : "present"), clock_in: hhmm(r?.clock_in ?? null), clock_out: hhmm(r?.clock_out ?? null), notes: r?.notes ?? "" }];
    })));
  }, [existing.data, employees]);

  const recorded = existing.data?.data.length ?? 0;
  const save = useAction(() => api.post("admin/hr/attendance", {
    date: day,
    rows: Object.values(rows).map((r) => ({ ...r, clock_in: r.clock_in || null, clock_out: r.clock_out || null, notes: r.notes || null })),
  }), { invalidate: [["admin/hr/attendance"]] });
  const set = (id: number, patch: Partial<Row>) => setRows((all) => ({ ...all, [id]: { ...all[id], ...patch } }));
  const editable = can("attendance.record");

  return (
    <Panel padded={false}
      title={<span className="flex items-center gap-3 whitespace-nowrap">{t("Daily register")}<Input type="date" value={day} max={today()} onChange={(e) => setDay(e.target.value)} className="h-9 w-40" /></span>}
      actions={editable && (
        <div className="flex gap-2">
          <Button size="sm" variant="secondary" icon={<CheckCheck className="size-4" />} onClick={() => setRows((all) => Object.fromEntries(Object.entries(all).map(([k, r]) => [k, r.status === "on_leave" ? r : { ...r, status: "present" as Status }])))}>{t("Mark all present")}</Button>
          <Button size="sm" icon={<Save className="size-4" />} loading={save.isPending} disabled={!employees.length} onClick={() => save.mutate(undefined)}>{t("Save register")}</Button>
        </div>
      )}>
      <p className="border-b border-ink-100 px-4 py-2 text-xs text-ink-500">{recorded ? t("{count} record(s) already saved for this day. Saving again updates them.", { count: recorded }) : t("Nothing recorded for this day yet. Staff who clock in themselves appear here automatically.")}</p>
      {existing.isLoading ? <Spinner /> : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-500">
              <tr><th className="px-4 py-2">{t("Employee")}</th><th className="px-2 py-2">{t("Status")}</th><th className="px-2 py-2">{t("In")}</th><th className="px-2 py-2">{t("Out")}</th><th className="px-2 py-2">{t("Note")}</th></tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {employees.map((e) => {
                const r = rows[e.id];
                if (!r) return null;
                return (
                  <tr key={e.id}>
                    <td className="px-4 py-2"><span className="font-medium text-ink-900">{e.full_name}</span><span className="block text-xs text-ink-500">{e.employee_no}</span></td>
                    <td className="px-2 py-2">
                      <div className="flex flex-wrap gap-1">
                        {STATUSES.map((s) => (
                          <button key={s} type="button" disabled={!editable} onClick={() => set(e.id, { status: s })}
                            className={cn("rounded-full px-2 py-0.5 text-xs font-medium transition", r.status === s ? STATUS_STYLE[s] : "bg-ink-100 text-ink-500 hover:bg-ink-200")}>
                            {t(STATUS_LABEL[s])}
                          </button>
                        ))}
                      </div>
                    </td>
                    <td className="px-2 py-2"><Input type="time" value={r.clock_in} disabled={!editable} onChange={(ev) => set(e.id, { clock_in: ev.target.value })} className="h-8 w-28" aria-label={t("In")} /></td>
                    <td className="px-2 py-2"><Input type="time" value={r.clock_out} disabled={!editable} onChange={(ev) => set(e.id, { clock_out: ev.target.value })} className="h-8 w-28" aria-label={t("Out")} /></td>
                    <td className="px-2 py-2"><Input value={r.notes} disabled={!editable} onChange={(ev) => set(e.id, { notes: ev.target.value })} className="h-8 min-w-32" aria-label={t("Note")} /></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  );
}

function MonthSummary() {
  const t = useT();
  const [month, setMonth] = useState(today().slice(0, 7));
  const q = useApi<ApiResponse<Summary[]>>("admin/hr/attendance/summary", { month });
  const rows = useMemo(() => q.data?.data ?? [], [q.data]);
  return (
    <Panel padded={false} title={<span className="flex items-center gap-3">{t("Month summary")}<Input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="h-9 w-40" /></span>}>
      <DataTable rows={rows} loading={q.isFetching} rowKey={(r) => r.employee_id}
        columns={[
          { key: "employee", header: t("Employee"), render: (r) => <span className="font-medium">{r.employee}<span className="block text-xs font-normal text-ink-500">{r.employee_no}</span></span> },
          { key: "days_present", header: t("Days present"), align: "right", render: (r) => <span className="num">{r.days_present}</span> },
          { key: "days_late", header: t("Late"), align: "right", render: (r) => <span className={cn("num", r.days_late > 2 && "font-semibold text-saffron-700")}>{r.days_late}</span> },
          { key: "days_absent", header: t("Absent"), align: "right", render: (r) => <span className={cn("num", r.days_absent > 0 && "font-semibold text-ember-600")}>{r.days_absent}</span> },
          { key: "days_on_leave", header: t("On leave"), align: "right", render: (r) => <span className="num">{r.days_on_leave}</span> },
          { key: "hours", header: t("Hours"), align: "right", render: (r) => <span className="num">{r.hours}</span> },
          { key: "overtime", header: t("Overtime"), align: "right", render: (r) => <span className="num">{r.overtime}</span> },
        ]}
        empty={{ title: t("No attendance recorded this month") }} />
    </Panel>
  );
}

export default function AttendancePage() {
  const t = useT();
  const [tab, setTab] = useState<"day" | "month">("day");
  return (
    <Guard perm="attendance.view">
      <PageHeader title={t("Attendance")} description={t("Mark who came to work. Absences reduce pay in payroll; lateness is measured against the work start time in Settings.")} />
      <div className="mb-4"><Tabs value={tab} onChange={setTab} tabs={[{ value: "day", label: t("Daily register") }, { value: "month", label: t("Month summary") }]} /></div>
      {tab === "day" ? <Register /> : <MonthSummary />}
    </Guard>
  );
}
