"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useState } from "react";
import { ArrowLeft, Download, FileUp, KeyRound, Pencil, Trash2, UserRound, UserX } from "lucide-react";
import { api, download, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, toNumber, humanize } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DescriptionList, Field, Input, Modal, PageHeader, Panel, Select, Spinner, Textarea } from "@/components/ui";
import { EmployeeForm } from "@/features/hr/components/EmployeeForm";
import { EmployeeLoginDialog } from "@/features/hr/components/EmployeeLoginDialog";
import { EMPLOYEE_STATUS_TONE, type Employee, type LeaveBalance } from "@/features/hr/types";

const DOC_TYPES = ["contract", "id", "certificate", "cv", "letter", "other"];

export default function EmployeePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const q = useApi<ApiResponse<{ employee: Employee; leave_balances: LeaveBalance[] }>>(`admin/hr/employees/${id}`);
  const [editing, setEditing] = useState(false);
  const [loginOpen, setLoginOpen] = useState(false);
  const [ending, setEnding] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [exit, setExit] = useState({ status: "resigned", exit_date: new Date().toISOString().slice(0, 10), reason: "" });
  const [doc, setDoc] = useState({ title: "", type: "contract", expires_on: "", file: null as File | null });
  const inv = [[`admin/hr/employees/${id}`], ["admin/hr/employees"]];
  const terminate = useAction(() => api.post(`admin/hr/employees/${id}/terminate`, exit), { invalidate: inv, onSuccess: () => setEnding(false) });
  const suspend = useAction((status: string) => api.put(`admin/hr/employees/${id}/status`, { status }), { invalidate: inv });
  const remove = useAction(() => api.del(`admin/hr/employees/${id}`), { invalidate: inv, onSuccess: () => router.replace("/admin/hr/employees") });
  const upload = useAction(() => {
    const fd = new FormData();
    fd.append("title", doc.title);
    fd.append("type", doc.type);
    if (doc.expires_on) fd.append("expires_on", doc.expires_on);
    if (doc.file) fd.append("file", doc.file);
    return api.post(`admin/hr/employees/${id}/documents`, fd);
  }, { invalidate: inv, onSuccess: () => { setUploading(false); setDoc({ title: "", type: "contract", expires_on: "", file: null }); } });
  const removeDoc = useAction((docId: number) => api.del(`admin/hr/employees/${id}/documents/${docId}`), { invalidate: inv });
  const ue = fieldErrors(upload.error);

  const d = q.data?.data;
  if (!d) return <Guard perm="employees.view"><Spinner variant="page" /></Guard>;
  const e = d.employee;
  const employed = ["active", "on_leave", "suspended"].includes(e.status);

  return (
    <Guard perm="employees.view">
      <PageHeader
        back={<Link href="/admin/hr/employees" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Employees")}</Link>}
        title={e.full_name}
        description={<span className="flex flex-wrap items-center gap-2">{e.employee_no} · {e.position?.title ?? t("No position")}{e.department && ` · ${e.department.name}`}<Badge tone={EMPLOYEE_STATUS_TONE[e.status]}>{t(humanize(e.status))}</Badge></span>}
        actions={
          <>
            {can("employees.update") && employed && <Button variant="ghost" onClick={() => suspend.mutate(e.status === "suspended" ? "active" : "suspended")}>{e.status === "suspended" ? t("Reinstate") : t("Suspend")}</Button>}
            {can("employees.terminate") && employed && <Button variant="secondary" icon={<UserX className="size-4" />} onClick={() => setEnding(true)}>{t("End employment")}</Button>}
            {can("employees.delete") && <Button variant="ghost" aria-label={t("Delete")} onClick={() => setDeleting(true)}><Trash2 className="size-4 text-ember-600" /></Button>}
            {can("employees.update") && <Button icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>{t("Edit")}</Button>}
          </>
        }
      />
      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <div className="space-y-5">
          <Panel title={t("Personal")}>
            <div className="flex gap-4">
              {e.photo_url
                // eslint-disable-next-line @next/next/no-img-element
                ? <img src={e.photo_url} alt="" className="size-20 shrink-0 rounded-full object-cover ring-2 ring-ink-100" />
                : <span className="grid size-20 shrink-0 place-items-center rounded-full bg-ink-100 text-ink-400"><UserRound className="size-8" /></span>}
              <DescriptionList items={[
                ["Phone", e.phone], ["Email", e.email], ["Address", e.address], ["Gender", e.gender ? t(e.gender === "male" ? "Male" : "Female") : null],
                ["Date of birth", e.date_of_birth ? date(e.date_of_birth) : null], ["Emergency contact", e.emergency_contact_name ? `${e.emergency_contact_name} (${e.emergency_contact_relation ?? ""}) ${e.emergency_contact_phone ?? ""}` : null],
                ...(can("employees.view_salary") ? [["National ID", e.national_id] as [string, React.ReactNode]] : []),
              ]} />
            </div>
          </Panel>
          <Panel title={t("Job")}>
            <DescriptionList items={[
              ["Employment type", t(humanize(e.employment_type))], ["Start date", date(e.hire_date)], ["Probation ends", e.probation_end_date ? date(e.probation_end_date) : null],
              ["Contract ends", e.contract_end_date ? date(e.contract_end_date) : null], ["Branch", e.branch?.name], ["Reports to", e.manager ? `${e.manager.first_name} ${e.manager.last_name}` : null],
              ["System login", <span key="login" className="flex flex-wrap items-center gap-2">{e.user ? `${e.user.username}${e.user.is_active ? "" : ` (${t("disabled")})`}` : t("None")}{can("employees.update") && <Button size="sm" variant="secondary" icon={<KeyRound className="size-3.5" />} onClick={() => setLoginOpen(true)}>{e.user ? t("Change login") : t("Create login")}</Button>}</span>],
              ...(e.exit_date ? [["Left on", `${date(e.exit_date)} — ${e.exit_reason ?? ""}`] as [string, React.ReactNode]] : []),
            ]} />
          </Panel>
          {can("employees.view_salary") && (
            <Panel title={t("Pay")}>
              <DescriptionList items={[
                ["Basic salary", <span key="b" className="num font-semibold">{money(e.basic_salary)}</span>], ["Paid by", t(e.payment_method === "mobile_money" ? "Mobile money" : e.payment_method === "bank" ? "Bank" : "Cash")],
                ["Bank", e.bank_name ? `${e.bank_name} ${e.bank_account_number ?? ""}` : null], ["Mobile money", e.mobile_money_number], ["TIN", e.tin], ["NSSF", e.nssf_number],
                ["Exemptions", [e.paye_exempt && "PAYE", e.nssf_exempt && "NSSF"].filter(Boolean).join(", ") || t("None")],
              ]} />
              {Boolean(e.components?.length) && (
                <ul className="mt-4 divide-y divide-ink-100 border-t border-ink-100 text-sm">
                  {e.components!.map((c) => (
                    <li key={c.id} className="flex justify-between py-1.5">
                      <span>{c.name} <Badge tone={c.type === "earning" ? "green" : "saffron"}>{c.type === "earning" ? t("allowance") : t("deduction")}</Badge></span>
                      <span className="num">{c.calculation === "percent" ? `${toNumber(c.pivot.amount ?? c.amount)}%` : money(c.pivot.amount ?? c.amount)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}
        </div>
        <div className="space-y-5">
          <Panel title={t("Leave this year")}>
            <ul className="space-y-2 text-sm">
              {d.leave_balances.map((b) => (
                <li key={b.leave_type_id} className="flex items-center justify-between">
                  <span>{b.leave_type?.name}</span>
                  <span className="num"><span className="font-semibold">{b.remaining}</span> <span className="text-xs text-ink-500">{t("of {total} left", { total: toNumber(b.entitled) + toNumber(b.carried_forward) + toNumber(b.adjustment) })}</span></span>
                </li>
              ))}
            </ul>
          </Panel>
          {can("employees.documents") && (
            <Panel title={t("Documents")} actions={<Button size="sm" variant="secondary" icon={<FileUp className="size-4" />} onClick={() => setUploading(true)}>{t("Upload")}</Button>}>
              {!e.documents?.length ? <p className="text-sm text-ink-500">{t("No documents yet. Keep contracts, IDs and certificates here (stored privately).")}</p> : (
                <ul className="divide-y divide-ink-100 text-sm">
                  {e.documents.map((dc) => (
                    <li key={dc.id} className="flex items-center justify-between gap-2 py-2">
                      <span className="min-w-0"><span className="block truncate font-medium">{dc.title}</span><span className="text-xs text-ink-500">{t(dc.type)}{dc.expires_on && ` · ${t("expires {date}", { date: date(dc.expires_on) })}`}</span></span>
                      <span className="flex shrink-0 gap-1">
                        <Button size="sm" variant="ghost" aria-label={t("Download")} onClick={() => download(`admin/hr/employees/${id}/documents/${dc.id}`)}><Download className="size-4" /></Button>
                        <Button size="sm" variant="ghost" aria-label={t("Delete")} onClick={() => confirm(t("Delete this document?")) && removeDoc.mutate(dc.id)}><Trash2 className="size-4 text-ember-600" /></Button>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}
        </div>
      </div>

      <EmployeeForm open={editing} onClose={() => setEditing(false)} employee={e} />
      <EmployeeLoginDialog open={loginOpen} onClose={() => setLoginOpen(false)} employee={e} />
      <Modal open={ending} onClose={() => setEnding(false)} title={t("End employment of {name}", { name: e.full_name })} description={t("Their system login is disabled immediately. Records are kept.")}
        footer={<><Button variant="secondary" onClick={() => setEnding(false)}>{t("Cancel")}</Button><Button loading={terminate.isPending} disabled={exit.reason.trim().length < 3} onClick={() => terminate.mutate(undefined)}>{t("Confirm")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Reason for leaving")}>{(p) => <Select {...p} value={exit.status} onChange={(ev) => setExit({ ...exit, status: ev.target.value })}><option value="resigned">{t("Resigned")}</option><option value="terminated">{t("Terminated")}</option></Select>}</Field>
          <Field label={t("Last day")}>{(p) => <Input {...p} type="date" value={exit.exit_date} onChange={(ev) => setExit({ ...exit, exit_date: ev.target.value })} />}</Field>
          <Field label={t("Details")} required className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={exit.reason} onChange={(ev) => setExit({ ...exit, reason: ev.target.value })} />}</Field>
        </div>
      </Modal>
      <Modal open={uploading} onClose={() => setUploading(false)} title={t("Upload a document")}
        footer={<><Button variant="secondary" onClick={() => setUploading(false)}>{t("Cancel")}</Button><Button loading={upload.isPending} disabled={!doc.title || !doc.file} onClick={() => upload.mutate(undefined)}>{t("Upload")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Title")} required error={ue.title}>{(p) => <Input {...p} value={doc.title} onChange={(ev) => setDoc({ ...doc, title: ev.target.value })} />}</Field>
          <Field label={t("Type")}>{(p) => <Select {...p} value={doc.type} onChange={(ev) => setDoc({ ...doc, type: ev.target.value })}>{DOC_TYPES.map((ty) => <option key={ty} value={ty}>{t(ty)}</option>)}</Select>}</Field>
          <Field label={t("Expires on")}>{(p) => <Input {...p} type="date" value={doc.expires_on} onChange={(ev) => setDoc({ ...doc, expires_on: ev.target.value })} />}</Field>
          <Field label={t("File")} required error={ue.file} hint={t("PDF, image or Word, up to 10 MB.")}>{(p) => <Input {...p} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" className="py-1.5" onChange={(ev) => setDoc({ ...doc, file: ev.target.files?.[0] ?? null })} />}</Field>
        </div>
      </Modal>
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} onConfirm={() => remove.mutate(undefined)} loading={remove.isPending} title={t("Delete {name}?", { name: e.full_name })} message={t("The record is hidden and can be restored. Payroll history is kept.")} confirmLabel={t("Delete")} />
    </Guard>
  );
}
