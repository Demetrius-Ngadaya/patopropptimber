"use client";

import Link from "next/link";
import { use, useMemo, useState } from "react";
import { ArrowLeft, Banknote, CheckCircle2, Download, RefreshCw, XCircle } from "lucide-react";
import { api, download, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money, today, monthLabel } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Field, Input, Modal, PageHeader, Panel, SearchInput, Select, Spinner, Stat, Textarea } from "@/components/ui";
import { RUN_STATUS_TONE, type Payslip, type PayrollRun } from "@/features/hr/types";

function SlipDetail({ slip, onClose }: { slip: Payslip | null; onClose: () => void }) {
  const t = useT();
  if (!slip) return null;
  const line = (label: string, v: unknown, cls = "") => <div className={`flex justify-between py-1 ${cls}`}><span>{label}</span><span className="num">{money(v)}</span></div>;
  return (
    <Modal open onClose={onClose} title={slip.employee_name} description={[slip.position, slip.department].filter(Boolean).join(" · ")}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Close")}</Button><Button icon={<Download className="size-4" />} onClick={() => download(`admin/hr/payroll/${slip.payroll_run_id}/payslips/${slip.id}/pdf`)}>{t("Payslip PDF")}</Button></>}>
      <div className="grid gap-5 text-sm sm:grid-cols-2">
        <div>
          <p className="mb-1 text-xs font-semibold uppercase text-ink-500">{t("Earnings")}</p>
          {line(t("Basic salary"), slip.basic_salary)}
          {slip.earnings?.map((x) => <div key={x.name}>{line(x.name, x.amount)}</div>)}
          {Number(slip.unpaid_deduction) > 0 && line(t("Unpaid days ({days})", { days: Number(slip.unpaid_days) }), -Number(slip.unpaid_deduction), "text-ember-600")}
          {line(t("Gross pay"), slip.gross, "border-t border-ink-200 font-semibold")}
        </div>
        <div>
          <p className="mb-1 text-xs font-semibold uppercase text-ink-500">{t("Deductions")}</p>
          {line("PAYE", slip.paye)}
          {line(t("NSSF (employee)"), slip.nssf_employee)}
          {Number(slip.nhif_employee) > 0 && line(t("Health insurance"), slip.nhif_employee)}
          {slip.deductions?.map((x) => <div key={x.name}>{line(x.name, x.amount)}</div>)}
          {Number(slip.advance_recovery) > 0 && line(t("Advance recovery"), slip.advance_recovery)}
        </div>
      </div>
      <div className="mt-4 flex items-center justify-between rounded-lg bg-brand-50 px-4 py-3 keep-light"><span className="font-medium text-brand-800">{t("Net pay")}</span><span className="num text-xl font-bold text-brand-800">{money(slip.net)}</span></div>
      <p className="mt-3 text-xs text-ink-500">{t("Employer contributions: NSSF {nssf}, SDL {sdl}, WCF {wcf}.", { nssf: money(slip.nssf_employer), sdl: money(slip.sdl), wcf: money(slip.wcf) })} {slip.payment_method && `${t("Paid by")} ${t(slip.payment_method.replace("_", " "))}${slip.account_hint ? ` (${slip.account_hint})` : ""}.`}</p>
    </Modal>
  );
}

export default function PayrollRunPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useT();
  const { can, user } = useAuth();
  const q = useApi<ApiResponse<{ run: PayrollRun; payslips: Payslip[] }>>(`admin/hr/payroll/${id}`);
  const [search, setSearch] = useState("");
  const [slip, setSlip] = useState<Payslip | null>(null);
  const [paying, setPaying] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [pay, setPay] = useState({ method: "bank", reference: "", paid_on: today() });
  const [reason, setReason] = useState("");
  const inv = [[`admin/hr/payroll/${id}`], ["admin/hr/payroll"]];
  const recalc = useAction(() => api.post(`admin/hr/payroll/${id}/recalculate`, {}), { invalidate: inv });
  const approve = useAction(() => api.post(`admin/hr/payroll/${id}/approve`, {}), { invalidate: inv });
  const doPay = useAction(() => api.post(`admin/hr/payroll/${id}/pay`, pay), { invalidate: inv, onSuccess: () => setPaying(false) });
  const cancel = useAction(() => api.post(`admin/hr/payroll/${id}/cancel`, { reason }), { invalidate: inv, onSuccess: () => setCancelling(false) });
  const slips = useMemo(() => (q.data?.data.payslips ?? []).filter((s) => !search || s.employee_name.toLowerCase().includes(search.toLowerCase())), [q.data, search]);

  const d = q.data?.data;
  if (!d) return <Guard perm="payroll.view"><Spinner variant="page" /></Guard>;
  const r = d.run;
  const preparedByMe = r.creator?.id === user?.id;

  return (
    <Guard perm="payroll.view">
      <PageHeader
        back={<Link href="/admin/hr/payroll" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Payroll")}</Link>}
        title={t("Payroll {period}", { period: monthLabel(r.period) })}
        description={<span className="flex flex-wrap items-center gap-2">{r.number} · {date(r.period_start)} – {date(r.period_end)}<Badge tone={RUN_STATUS_TONE[r.status]}>{t(r.status)}</Badge></span>}
        actions={<>
          {r.status === "draft" && can("payroll.prepare") && <Button variant="secondary" icon={<RefreshCw className="size-4" />} loading={recalc.isPending} onClick={() => recalc.mutate(undefined)}>{t("Recalculate")}</Button>}
          {["draft", "approved"].includes(r.status) && can("payroll.approve") && <Button variant="ghost" icon={<XCircle className="size-4" />} onClick={() => setCancelling(true)}>{t("Cancel")}</Button>}
          {r.status === "draft" && can("payroll.approve") && <Button icon={<CheckCircle2 className="size-4" />} loading={approve.isPending} disabled={preparedByMe} title={preparedByMe ? t("Someone other than the preparer must approve.") : undefined} onClick={() => approve.mutate(undefined)}>{t("Approve")}</Button>}
          {r.status === "approved" && can("payroll.pay") && <Button icon={<Banknote className="size-4" />} onClick={() => setPaying(true)}>{t("Mark as paid")}</Button>}
        </>}
      />
      {r.status === "draft" && preparedByMe && can("payroll.approve") && <p className="mb-4 rounded-lg bg-saffron-50 px-4 py-2 text-sm text-saffron-700">{t("You prepared this payroll, so another person must approve it (separation of duties).")}</p>}
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <Stat label={t("Staff")} value={r.employees} />
        <Stat label={t("Gross pay")} value={money(r.gross)} />
        <Stat label={t("PAYE + NSSF + other")} value={money(Number(r.paye) + Number(r.nssf_employee) + Number(r.nhif_employee) + Number(r.other_deductions) + Number(r.advance_recovery))} tone="saffron" />
        <Stat label={t("Net pay")} value={money(r.net)} tone="brand" />
        <Stat label={t("Total cost to business")} value={money(r.employer_cost)} sub={t("SDL {sdl} · WCF {wcf}", { sdl: money(r.sdl), wcf: money(r.wcf) })} />
      </div>
      <Panel padded={false} title={t("Payslips")} actions={<SearchInput value={search} onChange={setSearch} placeholder={t("Find employee")} className="w-56" />}>
        <DataTable rows={slips} rowKey={(s) => s.id} onRowClick={setSlip}
          columns={[
            { key: "employee_name", header: t("Employee"), render: (s) => <span className="font-medium">{s.employee_name}<span className="block text-xs font-normal text-ink-500">{[s.employee?.employee_no, s.position].filter(Boolean).join(" · ")}</span></span> },
            { key: "basic", header: t("Basic"), align: "right", hideOnMobile: true, render: (s) => <span className="num">{money(s.basic_salary)}</span> },
            { key: "gross", header: t("Gross"), align: "right", render: (s) => <span className="num">{money(s.gross)}</span> },
            { key: "paye", header: "PAYE", align: "right", hideOnMobile: true, render: (s) => <span className="num">{money(s.paye)}</span> },
            { key: "nssf", header: "NSSF", align: "right", hideOnMobile: true, render: (s) => <span className="num">{money(s.nssf_employee)}</span> },
            { key: "other", header: t("Other"), align: "right", hideOnMobile: true, render: (s) => <span className="num">{money(Number(s.other_deductions) + Number(s.advance_recovery) + Number(s.nhif_employee))}</span> },
            { key: "net", header: t("Net"), align: "right", render: (s) => <span className="num font-semibold">{money(s.net)}</span> },
            { key: "_pdf", header: "", align: "right", render: (s) => <Button size="sm" variant="ghost" aria-label={t("Payslip PDF")} onClick={(ev) => { ev.stopPropagation(); download(`admin/hr/payroll/${r.id}/payslips/${s.id}/pdf`); }}><Download className="size-4" /></Button> },
          ]}
          empty={{ title: t("No payslips") }} />
      </Panel>
      <p className="mt-3 text-xs text-ink-500">
        {r.creator && t("Prepared by {name}.", { name: r.creator.name })} {r.approver && t("Approved by {name} on {date}.", { name: r.approver.name, date: dateTime(r.approved_at) })} {r.paid_at && t("Paid on {date}.", { date: date(r.paid_at) })} {r.notes}
      </p>

      <SlipDetail slip={slip} onClose={() => setSlip(null)} />
      <Modal open={paying} onClose={() => setPaying(false)} title={t("Mark salaries as paid")} description={t("Records the payment of {net} net pay out of the chosen account.", { net: money(r.net) })}
        footer={<><Button variant="secondary" onClick={() => setPaying(false)}>{t("Cancel")}</Button><Button loading={doPay.isPending} onClick={() => doPay.mutate(undefined)}>{t("Confirm payment")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Paid from")}>{(p) => <Select {...p} value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}><option value="bank">{t("Bank")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="cash">{t("Cash")}</option></Select>}</Field>
          <Field label={t("Date")}>{(p) => <Input {...p} type="date" max={today()} value={pay.paid_on} onChange={(e) => setPay({ ...pay, paid_on: e.target.value })} />}</Field>
          <Field label={t("Reference")} className="sm:col-span-2">{(p) => <Input {...p} value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} />}</Field>
        </div>
      </Modal>
      <Modal open={cancelling} onClose={() => setCancelling(false)} title={t("Cancel payroll {number}", { number: r.number })} description={r.status === "approved" ? t("The accounting entries are reversed and advance recoveries are given back.") : undefined}
        footer={<><Button variant="secondary" onClick={() => setCancelling(false)}>{t("Back")}</Button><Button variant="danger" loading={cancel.isPending} disabled={reason.trim().length < 3} onClick={() => cancel.mutate(undefined)}>{t("Cancel payroll")}</Button></>}>
        <Field label={t("Reason")} required>{(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>
      </Modal>
    </Guard>
  );
}
