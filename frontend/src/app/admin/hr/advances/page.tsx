"use client";

import { useState } from "react";
import { Banknote, Check, Plus, X } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Button, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, Select, StatusBadge, Tabs, Textarea } from "@/components/ui";
import { EmployeeSelect } from "@/features/hr/components/EmployeeSelect";
import { employeeName, type SalaryAdvance } from "@/features/hr/types";

export default function AdvancesPage() {
  const t = useT();
  const { can } = useAuth();
  const [status, setStatus] = useState("pending");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [paying, setPaying] = useState<SalaryAdvance | null>(null);
  const [form, setForm] = useState({ employee_id: "", amount: "", installments: "1", reason: "" });
  const [pay, setPay] = useState({ method: "cash", reference: "" });
  const list = useApi<Paginated<SalaryAdvance>>("admin/hr/advances", { status: status || undefined, page });
  const inv = [["admin/hr/advances"]];
  const create = useAction(() => api.post("admin/hr/advances", form), { invalidate: inv, onSuccess: () => { setCreating(false); setForm({ employee_id: "", amount: "", installments: "1", reason: "" }); } });
  const decide = useAction(({ id, approve }: { id: number; approve: boolean }) => api.post(`admin/hr/advances/${id}/decide`, { approve, note: approve ? null : prompt(t("Reason for rejecting")) }), { invalidate: inv });
  const doPay = useAction(() => api.post(`admin/hr/advances/${paying!.id}/pay`, pay), { invalidate: inv, onSuccess: () => setPaying(null) });
  const e = fieldErrors(create.error);
  const monthly = toNumber(form.amount) / Math.max(1, toNumber(form.installments));

  return (
    <Guard perm={["payroll.advances", "payroll.approve"]}>
      <PageHeader title={t("Salary advances")} description={t("Money paid to staff before payday. After it is paid, it is recovered from salary in equal monthly installments.")}
        actions={can("payroll.advances") && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t("New advance")}</Button>} />
      <Panel padded={false}>
        <div className="px-3 pt-2"><Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[{ value: "pending", label: t("Waiting") }, { value: "approved", label: t("To pay") }, { value: "paid", label: t("Being recovered") }, { value: "recovered", label: t("Recovered") }, { value: "rejected", label: t("Rejected") }, { value: "", label: t("All") }]} /></div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id}
          columns={[
            { key: "employee", header: t("Employee"), render: (r) => <span className="font-medium">{employeeName(r.employee)}<span className="block text-xs font-normal text-ink-500">{r.number} · {date(r.created_at)}</span></span> },
            { key: "amount", header: t("Amount"), align: "right", render: (r) => <span className="num font-semibold">{money(r.amount)}</span> },
            { key: "plan", header: t("Recovery"), hideOnMobile: true, render: (r) => t("{n} × {amount}", { n: r.installments, amount: money(r.monthly_deduction) }) },
            { key: "outstanding", header: t("Still owed"), align: "right", render: (r) => <span className="num">{money(r.outstanding)}</span> },
            { key: "reason", header: t("Reason"), hideOnMobile: true, render: (r) => <span className="line-clamp-2 max-w-xs text-ink-600">{r.reason ?? "—"}</span> },
            { key: "status", header: t("Status"), render: (r) => <StatusBadge status={r.status} /> },
            { key: "_a", header: "", align: "right", render: (r) => (
              <span className="flex justify-end gap-1">
                {r.status === "pending" && can("payroll.approve") && <>
                  <Button size="sm" variant="secondary" icon={<Check className="size-3.5" />} onClick={() => decide.mutate({ id: r.id, approve: true })}>{t("Approve")}</Button>
                  <Button size="sm" variant="ghost" icon={<X className="size-3.5" />} onClick={() => decide.mutate({ id: r.id, approve: false })}>{t("Reject")}</Button>
                </>}
                {r.status === "approved" && can("payroll.pay") && <Button size="sm" icon={<Banknote className="size-3.5" />} onClick={() => setPaying(r)}>{t("Pay")}</Button>}
              </span>
            ) },
          ]}
          empty={{ title: t("No advances here") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>

      <Modal open={creating} onClose={() => setCreating(false)} title={t("New salary advance")}
        footer={<><Button variant="secondary" onClick={() => setCreating(false)}>{t("Cancel")}</Button><Button loading={create.isPending} onClick={() => create.mutate(undefined)}>{t("Save")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Employee")} required error={e.employee_id} className="sm:col-span-2">{(p) => <EmployeeSelect {...p} value={form.employee_id} onChange={(v) => setForm({ ...form, employee_id: v })} />}</Field>
          <Field label={t("Amount")} required error={e.amount}>{(p) => <Input {...p} type="number" min={0} value={form.amount} onChange={(ev) => setForm({ ...form, amount: ev.target.value })} />}</Field>
          <Field label={t("Recover over (months)")} error={e.installments} hint={monthly ? t("{amount} per month", { amount: money(monthly) }) : undefined}>{(p) => <Select {...p} value={form.installments} onChange={(ev) => setForm({ ...form, installments: ev.target.value })}>{Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}</Select>}</Field>
          <Field label={t("Reason")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={form.reason} onChange={(ev) => setForm({ ...form, reason: ev.target.value })} />}</Field>
        </div>
      </Modal>
      <Modal open={!!paying} onClose={() => setPaying(null)} title={paying ? t("Pay advance {number}", { number: paying.number }) : ""} description={paying ? t("{amount} to {name}", { amount: money(paying.amount), name: employeeName(paying.employee) }) : undefined}
        footer={<><Button variant="secondary" onClick={() => setPaying(null)}>{t("Cancel")}</Button><Button loading={doPay.isPending} onClick={() => doPay.mutate(undefined)}>{t("Confirm payment")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Paid from")}>{(p) => <Select {...p} value={pay.method} onChange={(ev) => setPay({ ...pay, method: ev.target.value })}><option value="cash">{t("Cash")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="bank">{t("Bank")}</option></Select>}</Field>
          <Field label={t("Reference")}>{(p) => <Input {...p} value={pay.reference} onChange={(ev) => setPay({ ...pay, reference: ev.target.value })} />}</Field>
        </div>
      </Modal>
    </Guard>
  );
}
