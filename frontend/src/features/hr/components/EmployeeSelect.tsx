"use client";

import type { Paginated } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Select } from "@/components/ui";
import type { Employee } from "../types";

/** Currently employed staff, for forms that act on one employee. */
export function useEmployees(enabled = true) {
  const q = useApi<Paginated<Employee>>(enabled ? "admin/hr/employees" : null, { status: "employed", per_page: 200, sort: "first_name" });
  return q.data?.data ?? [];
}

export function EmployeeSelect({ value, onChange, allowAll, ...rest }: { value: string; onChange: (v: string) => void; allowAll?: boolean } & Omit<React.ComponentProps<typeof Select>, "value" | "onChange">) {
  const t = useT();
  const employees = useEmployees();
  return (
    <Select {...rest} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">{allowAll ? t("All employees") : t("Choose employee…")}</option>
      {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_no})</option>)}
    </Select>
  );
}
