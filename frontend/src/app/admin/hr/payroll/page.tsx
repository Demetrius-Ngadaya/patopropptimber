"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Calculator, Landmark, Plus } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, today, toNumber, monthLabel } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { ResourcePage } from "@/components/resource/ResourcePage";
import { Badge, Button, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, Select, Tabs, Textarea } from "@/components/ui";
import { RUN_STATUS_TONE, type PayrollRun } from "@/features/hr/types";

const lastMonth = () => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 1); return d.toISOString().slice(0, 7); };
type Preview = { gross: number; taxable_income: number; paye: number; nssf_employee: number; nssf_employer: number; nhif_employee: number; sdl: number; wcf: number; net: number };

function Runs() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [preparing, setPreparing] = useState(false);
  const [form, setForm] = useState({ period: lastMonth(), notes: "" });
  const list = useApi<Paginated<PayrollRun>>("admin/hr/payroll", { year, page });
  const prepare = useAction(() => api.post<ApiResponse<PayrollRun>>("admin/hr/payroll", form), { invalidate: [["admin/hr/payroll"]], onSuccess: (r) => router.push(`/admin/hr/payroll/${r.data.id}`) });
  const e = fieldErrors(prepare.error);
  return (
    <Panel padded={false} title={<span className="flex items-center gap-3">{t("Payroll runs")}<Select value={year} onChange={(ev) => { setYear(ev.target.value); setPage(1); }} className="h-9 w-28">{[0, 1, 2, 3].map((d) => { const y = String(new Date().getFullYear() - d); return <option key={y} value={y}>{y}</option>; })}</Select></span>}
      actions={can("payroll.prepare") && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setPreparing(true)}>{t("Prepare payroll")}</Button>}>
      <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/hr/payroll/${r.id}`)}
        columns={[
          { key: "period", header: t("Month"), render: (r) => <span className="font-medium">{monthLabel(r.period)}<span className="block text-xs font-normal text-ink-500">{r.number}{r.branch && ` · ${r.branch.name}`}</span></span> },
          { key: "employees", header: t("Staff"), align: "right", render: (r) => <span className="num">{r.employees}</span> },
          { key: "gross", header: t("Gross"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.gross)}</span> },
          { key: "paye", header: "PAYE", align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.paye)}</span> },
          { key: "net", header: t("Net pay"), align: "right", render: (r) => <span className="num font-semibold">{money(r.net)}</span> },
          { key: "employer_cost", header: t("Total cost"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.employer_cost)}</span> },
          { key: "status", header: t("Status"), render: (r) => <Badge tone={RUN_STATUS_TONE[r.status]}>{t(r.status)}</Badge> },
        ]}
        empty={{ title: t("No payroll for {year}", { year }), action: can("payroll.prepare") ? <Button onClick={() => setPreparing(true)}>{t("Prepare payroll")}</Button> : undefined }} />
      <Pagination meta={list.data?.meta} onPage={setPage} />
      <Modal open={preparing} onClose={() => setPreparing(false)} title={t("Prepare payroll")} description={t("Calculates pay for everyone employed in the month: allowances, unpaid leave and absences, PAYE, NSSF, SDL, WCF and advance recovery. Nothing is posted until it is approved.")}
        footer={<><Button variant="secondary" onClick={() => setPreparing(false)}>{t("Cancel")}</Button><Button icon={<Calculator className="size-4" />} loading={prepare.isPending} onClick={() => prepare.mutate(undefined)}>{t("Calculate")}</Button></>}>
        <div className="grid gap-4">
          <Field label={t("Month")} required error={e.period}>{(p) => <Input {...p} type="month" value={form.period} max={today().slice(0, 7)} onChange={(ev) => setForm({ ...form, period: ev.target.value })} />}</Field>
          <Field label={t("Notes")}>{(p) => <Textarea {...p} rows={2} value={form.notes} onChange={(ev) => setForm({ ...form, notes: ev.target.value })} />}</Field>
        </div>
      </Modal>
    </Panel>
  );
}

function Calculator_() {
  const t = useT();
  const [basic, setBasic] = useState("1000000");
  const [allow, setAllow] = useState("0");
  const b = useDebounced(basic);
  const a = useDebounced(allow);
  const q = useApi<ApiResponse<Preview>>(toNumber(b) >= 0 && b !== "" ? "admin/hr/payroll/preview" : null, { basic: b, allowances: a || 0 });
  const r = q.data?.data;
  const rows: Array<[string, number | undefined, boolean?]> = r ? [
    [t("Gross pay"), r.gross], [t("NSSF (employee)"), -r.nssf_employee], [t("Taxable income"), r.taxable_income], ["PAYE", -r.paye],
    ...(r.nhif_employee ? [[t("Health insurance"), -r.nhif_employee] as [string, number]] : []), [t("Take-home pay"), r.net, true],
  ] : [];
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Panel title={t("Salary calculator")}>
        <p className="mb-4 text-sm text-ink-500">{t("Check what an employee takes home and what they cost, using the rates in Settings → Payroll.")}</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Basic salary")}>{(p) => <Input {...p} type="number" min={0} value={basic} onChange={(e) => setBasic(e.target.value)} />}</Field>
          <Field label={t("Taxable allowances")}>{(p) => <Input {...p} type="number" min={0} value={allow} onChange={(e) => setAllow(e.target.value)} />}</Field>
        </div>
      </Panel>
      <Panel title={t("Result")}>
        {r ? (
          <dl className="space-y-1.5 text-sm">
            {rows.map(([k, v, strong]) => (
              <div key={k} className={strong ? "flex justify-between border-t border-ink-200 pt-2 text-base font-semibold" : "flex justify-between"}>
                <dt>{k}</dt><dd className="num">{money(v)}</dd>
              </div>
            ))}
            <div className="mt-3 rounded-lg bg-ink-50 p-3 text-xs text-ink-600">
              {t("Employer also pays: NSSF {nssf}, SDL {sdl}, WCF {wcf}. Total cost {total}.", { nssf: money(r.nssf_employer), sdl: money(r.sdl), wcf: money(r.wcf), total: money(r.gross + r.nssf_employer + r.sdl + r.wcf) })}
            </div>
          </dl>
        ) : <p className="text-sm text-ink-500">{t("Enter a salary.")}</p>}
      </Panel>
    </div>
  );
}

function Remit() {
  const t = useT();
  const [f, setF] = useState({ type: "paye", amount: "", method: "bank", reference: "", date: today() });
  const save = useAction(() => api.post("admin/hr/payroll/remittances", f), { onSuccess: () => setF({ ...f, amount: "", reference: "" }) });
  const e = fieldErrors(save.error);
  const labels: Record<string, string> = { paye: "PAYE (TRA)", nssf: "NSSF", sdl: "SDL (TRA)", wcf: "WCF", nhif: t("Health insurance"), other: t("Other deductions") };
  return (
    <Panel title={t("Pay statutory deductions")} className="max-w-2xl">
      <p className="mb-4 text-sm text-ink-500">{t("Record money paid to TRA, NSSF, WCF and others. It clears the amount owed in the accounts. Balances are on the trial balance.")}</p>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("What")} required>{(p) => <Select {...p} value={f.type} onChange={(ev) => setF({ ...f, type: ev.target.value })}>{Object.entries(labels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select>}</Field>
        <Field label={t("Amount")} required error={e.amount}>{(p) => <Input {...p} type="number" min={0} value={f.amount} onChange={(ev) => setF({ ...f, amount: ev.target.value })} />}</Field>
        <Field label={t("Paid from")}>{(p) => <Select {...p} value={f.method} onChange={(ev) => setF({ ...f, method: ev.target.value })}><option value="bank">{t("Bank")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="cash">{t("Cash")}</option></Select>}</Field>
        <Field label={t("Date")} required error={e.date}>{(p) => <Input {...p} type="date" max={today()} value={f.date} onChange={(ev) => setF({ ...f, date: ev.target.value })} />}</Field>
        <Field label={t("Reference / control number")} className="sm:col-span-2">{(p) => <Input {...p} value={f.reference} onChange={(ev) => setF({ ...f, reference: ev.target.value })} />}</Field>
      </div>
      <div className="mt-4 flex justify-end"><Button icon={<Landmark className="size-4" />} loading={save.isPending} disabled={!toNumber(f.amount)} onClick={() => save.mutate(undefined)}>{t("Record payment")}</Button></div>
    </Panel>
  );
}

const PAY_PERMS = { create: "payroll.settings", update: "payroll.settings", delete: "payroll.settings", restore: "payroll.settings" };

export default function PayrollPage() {
  const t = useT();
  const { can } = useAuth();
  const [tab, setTab] = useState<"runs" | "components" | "calculator" | "remit">("runs");
  return (
    <Guard perm="payroll.view">
      {tab !== "components" && <PageHeader title={t("Payroll")} description={t("Monthly salaries with Tanzanian statutory deductions. Prepared by HR, approved by someone else, then paid. Every step posts to the accounts.")} />}
      <div className="mb-4"><Tabs value={tab} onChange={setTab} tabs={[{ value: "runs", label: t("Payroll runs") }, { value: "components", label: t("Allowances & deductions") }, { value: "calculator", label: t("Salary calculator") }, ...(can("payroll.pay") ? [{ value: "remit" as const, label: t("Statutory payments") }] : [])]} /></div>
      {tab === "runs" && <Runs />}
      {tab === "calculator" && <Calculator_ />}
      {tab === "remit" && <Remit />}
      {tab === "components" && (
        <ResourcePage title={t("Allowances & deductions")} description={t("Regular extras and deductions. Mark one as for everyone, or assign it to individual employees on their profile.")} endpoint="admin/hr/salary-components" perm="payroll" perms={PAY_PERMS} noun="component"
          columns={[
            { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{String(r.name)}<span className="block text-xs font-normal text-ink-500">{String(r.code)}</span></span> },
            { key: "type", header: t("Type"), render: (r) => (r.type === "earning" ? <Badge tone="green">{t("allowance")}</Badge> : <Badge tone="saffron">{t("deduction")}</Badge>) },
            { key: "amount", header: t("Amount"), align: "right", render: (r) => <span className="num">{r.calculation === "percent" ? `${toNumber(r.amount)}% ${t("of basic")}` : money(r.amount)}</span> },
            { key: "flags", header: t("Taxed"), render: (r) => (r.type === "earning" ? [r.is_taxable && "PAYE", r.is_pensionable && "NSSF"].filter(Boolean).join(", ") || t("No") : "—") },
            { key: "applies_to_all", header: t("Everyone"), render: (r) => (r.applies_to_all ? <Badge tone="blue">{t("Yes")}</Badge> : t("Assigned")) },
          ]}
          fields={[
            { name: "name", label: t("Name"), required: true, placeholder: t("Transport allowance") },
            { name: "code", label: t("Code"), required: true, placeholder: "TRANSPORT" },
            { name: "type", label: t("Type"), type: "select", required: true, options: [{ value: "earning", label: t("Allowance (adds to pay)") }, { value: "deduction", label: t("Deduction (reduces pay)") }] },
            { name: "calculation", label: t("Calculated as"), type: "select", required: true, options: [{ value: "fixed", label: t("Fixed amount") }, { value: "percent", label: t("Percent of basic salary") }] },
            { name: "amount", label: t("Default amount"), type: "number", required: true },
            { name: "is_taxable", label: t("Subject to PAYE"), type: "switch" },
            { name: "is_pensionable", label: t("Subject to NSSF"), type: "switch" },
            { name: "applies_to_all", label: t("Applies to every employee"), type: "switch" },
            { name: "is_active", label: t("Active"), type: "switch" },
          ]}
          defaults={{ name: "", code: "", type: "earning", calculation: "fixed", amount: 0, is_taxable: true, is_pensionable: false, applies_to_all: false, is_active: true }} />
      )}
    </Guard>
  );
}
