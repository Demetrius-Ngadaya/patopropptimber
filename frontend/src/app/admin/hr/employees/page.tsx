"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Plus, RotateCcw, UserRound } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, humanize } from "@/lib/format";
import { useAction, useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, DataTable, PageHeader, Pagination, Panel, SearchInput, Tabs } from "@/components/ui";
import { EmployeeForm } from "@/features/hr/components/EmployeeForm";
import { EMPLOYEE_STATUS_TONE, type Employee } from "@/features/hr/types";

export default function EmployeesPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [status, setStatus] = useState("employed");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [trashed, setTrashed] = useState(false);
  const [creating, setCreating] = useState(false);
  const q = useDebounced(search);
  const list = useApi<Paginated<Employee>>("admin/hr/employees", { status: status || undefined, search: q, page, trashed: trashed ? "only" : undefined });
  const restore = useAction((id: number) => api.post(`admin/hr/employees/${id}/restore`), { invalidate: [["admin/hr/employees"]] });

  return (
    <Guard perm="employees.view">
      <PageHeader title={t("Employees")} description={t("Everyone who works for the business: contracts, pay details, documents, leave and attendance.")}
        actions={can("employees.create") && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t("Add employee")}</Button>} />
      <Panel padded={false}>
        <div className="px-3 pt-2"><Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[{ value: "employed", label: t("Working") }, { value: "on_leave", label: t("On leave") }, { value: "suspended", label: t("Suspended") }, { value: "terminated", label: t("Terminated") }, { value: "resigned", label: t("Resigned") }, { value: "", label: t("All") }]} /></div>
        <div className="flex flex-wrap items-center gap-3 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Name, number or phone")} className="w-full max-w-sm" />
          {can("employees.restore") && <Checkbox label={t("Show deleted")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} />}
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={trashed ? undefined : (r) => router.push(`/admin/hr/employees/${r.id}`)}
          columns={[
            { key: "name", header: t("Employee"), render: (r) => (
              <span className="flex items-center gap-2.5">
                {r.photo_url
                  // eslint-disable-next-line @next/next/no-img-element
                  ? <img src={r.photo_url} alt="" className="size-9 rounded-full object-cover" />
                  : <span className="grid size-9 place-items-center rounded-full bg-ink-100 text-ink-500"><UserRound className="size-4" /></span>}
                <span className="font-medium text-ink-900">{r.full_name}<span className="block text-xs font-normal text-ink-500">{r.employee_no}</span></span>
              </span>
            ) },
            { key: "position", header: t("Position"), render: (r) => <span>{r.position?.title ?? "—"}<span className="block text-xs text-ink-500">{r.department?.name}</span></span> },
            { key: "phone", header: t("Phone"), hideOnMobile: true },
            { key: "hire_date", header: t("Started"), hideOnMobile: true, render: (r) => date(r.hire_date) },
            ...(can("employees.view_salary") ? [{ key: "basic_salary", header: t("Basic salary"), align: "right" as const, hideOnMobile: true, render: (r: Employee) => <span className="num">{money(r.basic_salary)}</span> }] : []),
            { key: "status", header: t("Status"), render: (r) => <Badge tone={EMPLOYEE_STATUS_TONE[r.status]}>{t(humanize(r.status))}</Badge> },
            ...(trashed ? [{ key: "_r", header: "", align: "right" as const, render: (r: Employee) => <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => restore.mutate(r.id)}>{t("Restore")}</Button> }] : []),
          ]}
          empty={{ title: t("No employees found"), action: can("employees.create") ? <Button onClick={() => setCreating(true)}>{t("Add employee")}</Button> : undefined }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      <EmployeeForm open={creating} onClose={() => setCreating(false)} onSaved={(e) => router.push(`/admin/hr/employees/${e.id}`)} />
    </Guard>
  );
}
