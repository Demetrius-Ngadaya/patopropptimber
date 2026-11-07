"use client";

import { useSearchParams } from "next/navigation";
import { Suspense } from "react";
import type { Paginated } from "@/lib/api";
import { money } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge } from "@/components/ui";
import { SubNav } from "@/features/hr/components/HrTabs";

const MANAGE = { create: "departments.manage", update: "departments.manage", delete: "departments.manage", restore: "departments.manage" };

function Content() {
  const t = useT();
  const tab = useSearchParams().get("tab") === "positions" ? "positions" : "departments";
  const depts = useApi<Paginated<{ id: number; name: string }>>("admin/hr/departments", { per_page: 200 });
  const deptOptions = (depts.data?.data ?? []).map((d) => ({ value: d.id, label: d.name }));
  const active = (r: Record<string, unknown>) => (r.is_active ? <Badge tone="green">{t("Active")}</Badge> : <Badge tone="red">{t("Inactive")}</Badge>);

  return (
    <>
      <SubNav active={`?tab=${tab}`} items={[{ href: "?tab=departments", label: t("Departments") }, { href: "?tab=positions", label: t("Positions") }]} />
      {tab === "departments" ? (
        <ResourcePage key="d" title={t("Departments")} description={t("Group employees by the part of the business they work in.")} endpoint="admin/hr/departments" perm="departments" perms={MANAGE} noun="department"
          columns={[
            { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{String(r.name)}</span> },
            { key: "code", header: t("Code") },
            { key: "employees_count", header: t("Employees"), align: "right", render: (r) => <span className="num">{String(r.employees_count ?? 0)}</span> },
            { key: "is_active", header: t("Status"), render: active },
          ]}
          fields={[
            { name: "name", label: t("Name"), required: true, placeholder: t("Sales") },
            { name: "code", label: t("Code"), placeholder: "SAL" },
            { name: "description", label: t("Description"), type: "textarea", span: 2 },
            { name: "is_active", label: t("Active"), type: "switch" },
          ]}
          defaults={{ name: "", code: "", description: "", is_active: true }} />
      ) : (
        <ResourcePage key="p" title={t("Positions")} description={t("Job titles with an optional salary band. Pay outside the band shows a warning on the employee form.")} endpoint="admin/hr/positions" perm="departments" perms={MANAGE} noun="position"
          columns={[
            { key: "title", header: t("Title"), render: (r) => <span className="font-medium">{String(r.title)}</span> },
            { key: "department", header: t("Department"), render: (r) => (r.department as { name?: string } | null)?.name ?? "—" },
            { key: "grade", header: t("Grade") },
            { key: "band", header: t("Salary band"), render: (r) => (r.min_salary || r.max_salary ? <span className="num">{money(r.min_salary)} – {money(r.max_salary)}</span> : "—") },
            { key: "is_active", header: t("Status"), render: active },
          ]}
          fields={[
            { name: "title", label: t("Title"), required: true, placeholder: t("Cashier") },
            { name: "department_id", label: t("Department"), type: "select", options: deptOptions },
            { name: "grade", label: t("Grade") },
            { name: "min_salary", label: t("Minimum salary"), type: "number" },
            { name: "max_salary", label: t("Maximum salary"), type: "number" },
            { name: "description", label: t("Description"), type: "textarea", span: 2 },
            { name: "is_active", label: t("Active"), type: "switch" },
          ]}
          toForm={(r) => ({ title: r.title, department_id: r.department_id ?? "", grade: r.grade ?? "", min_salary: r.min_salary ?? "", max_salary: r.max_salary ?? "", description: r.description ?? "", is_active: r.is_active })}
          defaults={{ title: "", department_id: "", grade: "", min_salary: "", max_salary: "", description: "", is_active: true }} />
      )}
    </>
  );
}

export default function DepartmentsPage() {
  return <Guard perm="departments.view"><Suspense><Content /></Suspense></Guard>;
}
