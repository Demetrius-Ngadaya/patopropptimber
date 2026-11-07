"use client";

import { useEffect, useState } from "react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Button, Checkbox, Field, Input, Modal, Select, Tabs, Textarea } from "@/components/ui";
import type { Employee } from "../types";

type Lookup = { id: number; name?: string; title?: string };
type Component = { id: number; name: string; type: "earning" | "deduction"; calculation: "fixed" | "percent"; amount: string };
type Form = Record<string, string | boolean>;

const TEXT = ["first_name", "middle_name", "last_name", "gender", "date_of_birth", "marital_status", "national_id", "phone", "email", "address", "emergency_contact_name", "emergency_contact_phone", "emergency_contact_relation",
  "branch_id", "department_id", "position_id", "manager_id", "employment_type", "hire_date", "probation_end_date", "contract_end_date", "basic_salary", "payment_method", "bank_name", "bank_account_number", "mobile_money_number", "tin", "nssf_number", "nhif_number", "notes"] as const;

const blank = (): Form => ({ ...Object.fromEntries(TEXT.map((k) => [k, ""])), employment_type: "permanent", payment_method: "bank", hire_date: new Date().toISOString().slice(0, 10), paye_exempt: false, nssf_exempt: false });

export function EmployeeForm({ open, onClose, employee, onSaved }: { open: boolean; onClose: () => void; employee?: Employee | null; onSaved?: (e: Employee) => void }) {
  const t = useT();
  const { can, user } = useAuth();
  const canSalary = can("employees.view_salary");
  const [tab, setTab] = useState<"personal" | "job" | "pay" | "login">("personal");
  const [form, setForm] = useState<Form>(blank);
  const [components, setComponents] = useState<Array<{ salary_component_id: number; amount: string }>>([]);
  const [login, setLogin] = useState({ create: false, username: "", password: "" });
  const [photo, setPhoto] = useState<File | null>(null);

  const departments = useApi<ApiResponse<Lookup[]>>(open ? "admin/hr/departments" : null, { all: 1, active: 1 });
  const positions = useApi<ApiResponse<Lookup[]>>(open ? "admin/hr/positions" : null, { all: 1, active: 1 });
  const managers = useApi<Paginated<Employee>>(open ? "admin/hr/employees" : null, { status: "employed", per_page: 200 });
  const salaryComponents = useApi<ApiResponse<Component[]>>(open && canSalary ? "admin/hr/salary-components" : null, { all: 1, active: 1 });

  useEffect(() => {
    if (!open) return;
    setTab("personal");
    setPhoto(null);
    setLogin({ create: false, username: "", password: "" });
    if (employee) {
      const f = blank();
      for (const k of TEXT) {
        const v = (employee as unknown as Record<string, unknown>)[k];
        f[k] = v === null || v === undefined ? "" : String(v).slice(0, k.endsWith("date") || k === "hire_date" ? 10 : undefined);
      }
      f.paye_exempt = Boolean(employee.paye_exempt);
      f.nssf_exempt = Boolean(employee.nssf_exempt);
      setForm(f);
      setComponents((employee.components ?? []).map((c) => ({ salary_component_id: c.id, amount: c.pivot.amount ?? "" })));
    } else {
      setForm(blank());
      setComponents([]);
    }
  }, [open, employee]);

  const save = useAction(() => {
    const fd = new FormData();
    const PAY = ["basic_salary", "payment_method", "bank_name", "bank_account_number", "mobile_money_number", "tin", "nssf_number", "nhif_number", "national_id", "paye_exempt", "nssf_exempt"];
    for (const [k, v] of Object.entries(form)) {
      if (!canSalary && employee && PAY.includes(k)) continue; // not visible to this user: keep what is stored
      if (typeof v === "boolean") fd.append(k, v ? "1" : "0");
      else if (v !== "") fd.append(k, v);
      else if (employee) fd.append(k, "");
    }
    if (canSalary) components.forEach((c, i) => {
      fd.append(`components[${i}][salary_component_id]`, String(c.salary_component_id));
      if (c.amount !== "") fd.append(`components[${i}][amount]`, c.amount);
    });
    if (photo) fd.append("photo", photo);
    if (!employee && login.create) {
      fd.append("login[username]", login.username);
      fd.append("login[password]", login.password);
    }
    return employee ? api.post<ApiResponse<Employee>>(`admin/hr/employees/${employee.id}`, fd) : api.post<ApiResponse<Employee>>("admin/hr/employees", fd);
  }, { invalidate: [["admin/hr/employees"]], onSuccess: (res) => { onClose(); onSaved?.(res.data); } });
  const e = fieldErrors(save.error);
  const set = (k: string, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }));
  const text = (k: string, label: string, opts: { type?: string; required?: boolean; hint?: string; span?: boolean } = {}) => (
    <Field label={label} required={opts.required} hint={opts.hint} error={e[k]} className={opts.span ? "sm:col-span-2" : undefined}>
      {(p) => <Input {...p} type={opts.type ?? "text"} value={String(form[k] ?? "")} onChange={(ev) => set(k, ev.target.value)} />}
    </Field>
  );
  const select = (k: string, label: string, options: Array<[string, string]>, required = false) => (
    <Field label={label} required={required} error={e[k]}>
      {(p) => <Select {...p} value={String(form[k] ?? "")} onChange={(ev) => set(k, ev.target.value)}>{!required && <option value="">—</option>}{options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}
    </Field>
  );
  const errorTabs = { personal: ["first_name", "last_name", "phone", "email", "date_of_birth", "national_id"], job: ["hire_date", "employment_type", "contract_end_date", "department_id", "position_id"], pay: ["basic_salary", "payment_method", "bank_name", "bank_account_number", "mobile_money_number"], login: ["login.username", "login.password"] };
  const hasError = (k: keyof typeof errorTabs) => errorTabs[k].some((f) => e[f]);

  return (
    <Modal open={open} onClose={onClose} size="lg" title={employee ? t("Edit {name}", { name: employee.full_name }) : t("Add an employee")}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></>}>
      <Tabs value={tab} onChange={setTab} tabs={[
        { value: "personal", label: t("Personal") + (hasError("personal") ? " •" : "") },
        { value: "job", label: t("Job") + (hasError("job") ? " •" : "") },
        ...(canSalary ? [{ value: "pay" as const, label: t("Pay") + (hasError("pay") ? " •" : "") }] : []),
        ...(!employee ? [{ value: "login" as const, label: t("System login") }] : []),
      ]} />
      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        {tab === "personal" && (
          <>
            {text("first_name", t("First name"), { required: true })}
            {text("middle_name", t("Middle name"))}
            {text("last_name", t("Last name"), { required: true })}
            {select("gender", t("Gender"), [["female", t("Female")], ["male", t("Male")]])}
            {text("date_of_birth", t("Date of birth"), { type: "date" })}
            {select("marital_status", t("Marital status"), [["single", t("Single")], ["married", t("Married")], ["divorced", t("Divorced")], ["widowed", t("Widowed")]])}
            {text("phone", t("Phone"), { type: "tel" })}
            {text("email", t("Email"), { type: "email" })}
            {text("address", t("Residential address"), { span: true })}
            {canSalary && text("national_id", t("National ID (NIDA)"), { hint: t("Stored encrypted.") })}
            {text("emergency_contact_name", t("Emergency contact"))}
            {text("emergency_contact_phone", t("Emergency phone"), { type: "tel" })}
            {text("emergency_contact_relation", t("Relationship"))}
            <Field label={t("Photo")} hint={t("JPG or PNG up to 3 MB.")}>{(p) => <Input {...p} type="file" accept="image/png,image/jpeg,image/webp" className="py-1.5" onChange={(ev) => setPhoto(ev.target.files?.[0] ?? null)} />}</Field>
          </>
        )}
        {tab === "job" && (
          <>
            {select("employment_type", t("Employment type"), [["permanent", t("Permanent")], ["contract", t("Contract")], ["casual", t("Casual")], ["intern", t("Intern")]], true)}
            {text("hire_date", t("Start date"), { type: "date", required: true })}
            {text("probation_end_date", t("Probation ends"), { type: "date" })}
            {text("contract_end_date", t("Contract ends"), { type: "date", required: form.employment_type === "contract" })}
            {select("department_id", t("Department"), (departments.data?.data ?? []).map((d) => [String(d.id), d.name ?? ""]))}
            {select("position_id", t("Position"), (positions.data?.data ?? []).map((d) => [String(d.id), d.title ?? ""]))}
            {select("branch_id", t("Branch"), (user?.branches ?? []).map((b) => [String(b.id), b.name]))}
            {select("manager_id", t("Reports to"), (managers.data?.data ?? []).filter((m) => m.id !== employee?.id).map((m) => [String(m.id), m.full_name]))}
            <Field label={t("Notes")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={String(form.notes ?? "")} onChange={(ev) => set("notes", ev.target.value)} />}</Field>
          </>
        )}
        {tab === "pay" && canSalary && (
          <>
            {text("basic_salary", t("Basic salary (monthly)"), { type: "number", required: true })}
            {select("payment_method", t("Paid by"), [["bank", t("Bank")], ["mobile_money", t("Mobile money")], ["cash", t("Cash")]], true)}
            {form.payment_method === "bank" && <>{text("bank_name", t("Bank name"), { required: true })}{text("bank_account_number", t("Account number"), { required: true, hint: t("Stored encrypted.") })}</>}
            {form.payment_method === "mobile_money" && text("mobile_money_number", t("Mobile money number"), { required: true, type: "tel" })}
            {text("tin", t("TIN"), { hint: t("Stored encrypted.") })}
            {text("nssf_number", t("NSSF number"))}
            {text("nhif_number", t("Health insurance number"))}
            <div className="flex flex-col gap-2 sm:col-span-2">
              <Checkbox label={t("Exempt from PAYE")} checked={Boolean(form.paye_exempt)} onChange={(ev) => set("paye_exempt", ev.target.checked)} />
              <Checkbox label={t("Exempt from NSSF")} checked={Boolean(form.nssf_exempt)} onChange={(ev) => set("nssf_exempt", ev.target.checked)} />
            </div>
            <div className="sm:col-span-2">
              <p className="mb-2 text-[13px] font-medium text-ink-700">{t("Allowances and deductions")}</p>
              <div className="space-y-2">
                {(salaryComponents.data?.data ?? []).map((c) => {
                  const row = components.find((x) => x.salary_component_id === c.id);
                  return (
                    <div key={c.id} className="flex items-center gap-3">
                      <Checkbox label={<span>{c.name} <span className="text-xs text-ink-500">({c.type === "earning" ? t("allowance") : t("deduction")}, {c.calculation === "percent" ? `${Number(c.amount)}%` : Number(c.amount).toLocaleString()})</span></span>} checked={Boolean(row)}
                        onChange={(ev) => setComponents((cs) => ev.target.checked ? [...cs, { salary_component_id: c.id, amount: "" }] : cs.filter((x) => x.salary_component_id !== c.id))} />
                      {row && <Input aria-label={t("Amount for {name}", { name: c.name })} type="number" min={0} step="any" placeholder={t("default")} className="ml-auto w-32" value={row.amount} onChange={(ev) => setComponents((cs) => cs.map((x) => (x.salary_component_id === c.id ? { ...x, amount: ev.target.value } : x)))} />}
                    </div>
                  );
                })}
                {!salaryComponents.data?.data?.length && <p className="text-sm text-ink-500">{t("No allowances or deductions set up yet (Payroll > Settings).")}</p>}
              </div>
            </div>
          </>
        )}
        {tab === "login" && !employee && (
          <>
            <div className="sm:col-span-2"><Checkbox label={t("Create a login so the employee can see payslips, apply for leave and clock in")} checked={login.create} onChange={(ev) => setLogin({ ...login, create: ev.target.checked })} /></div>
            {login.create && (
              <>
                <Field label={t("Username")} required error={e["login.username"]}>{(p) => <Input {...p} autoComplete="off" value={login.username} onChange={(ev) => setLogin({ ...login, username: ev.target.value })} />}</Field>
                <Field label={t("Temporary password")} required error={e["login.password"]} hint={t("They must change it at first login.")}>{(p) => <Input {...p} type="password" autoComplete="new-password" value={login.password} onChange={(ev) => setLogin({ ...login, password: ev.target.value })} />}</Field>
              </>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}
