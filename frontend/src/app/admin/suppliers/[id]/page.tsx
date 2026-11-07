"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useState } from "react";
import { ArrowLeft, Pencil, Trash2, Wallet } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, monthStart, toNumber, today, humanize } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { Guard, Can } from "@/components/layout/Guard";
import { SupplierForm, type Supplier } from "@/features/purchasing/components/SupplierForm";
import { Button, ConfirmDialog, DataTable, DescriptionList, ErrorState, Field, Input, Modal, PageHeader, Panel, Select, Spinner, Stat, StatusBadge, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Detail = {
  supplier: Supplier;
  unpaid_purchases: Array<{ id: number; number: string; purchase_date: string; supplier_invoice_no: string | null; supplier_total: string; amount_paid: string; balance: string; due_date: string | null }>;
  recent_purchases: Array<{ id: number; number: string; purchase_date: string; supplier_total: string; balance: string; payment_status: string }>;
  recent_payments: Array<{ id: number; number: string; paid_on: string; amount: string; method: string; reference: string | null }>;
};
type Statement = { opening_balance: number; closing_balance: number; entries: Array<{ date: string; reference: string; description: string; debit: number; credit: number; balance: number }> };

export default function SupplierDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const router = useRouter();
  const { can } = useAuth();
  const q = useApi<ApiResponse<Detail>>(`admin/suppliers/${id}`);
  const [tab, setTab] = useState<"purchases" | "statement" | "payments">("purchases");
  const [range, setRange] = useState({ date_from: monthStart(), date_to: today() });
  const statement = useApi<ApiResponse<Statement>>(tab === "statement" ? `admin/suppliers/${id}/statement` : null, range);
  const [paying, setPaying] = useState(false);
  const [editing, setEditing] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [pay, setPay] = useState({ amount: 0, method: "bank", reference: "", paid_on: today(), notes: "" });
  const payAction = useAction(() => api.post(`admin/suppliers/${id}/payments`, pay), { invalidate: [[`admin/suppliers/${id}`], ["admin/suppliers"]], onSuccess: () => setPaying(false) });
  const remove = useAction(() => api.del(`admin/suppliers/${id}`), { invalidate: [["admin/suppliers"]], onSuccess: () => router.replace("/admin/suppliers") });
  const payErrors = fieldErrors(payAction.error);

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Supplier not found.")} />;
  const { supplier: s, unpaid_purchases, recent_purchases, recent_payments } = q.data.data;
  const owed = toNumber(s.balance);

  return (
    <Guard perm="suppliers.view">
      <PageHeader
        back={<Link href="/admin/suppliers" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Suppliers")}</Link>}
        title={s.name}
        description={`${s.code}${s.phone ? `, ${s.phone}` : ""}`}
        actions={
          <>
            {owed > 0 && <Can perm="suppliers.pay"><Button icon={<Wallet className="size-4" />} onClick={() => { setPay({ ...pay, amount: owed }); setPaying(true); }}>{t("Pay supplier")}</Button></Can>}
            <Can perm="suppliers.update"><Button variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>{t("Edit")}</Button></Can>
            <Can perm="suppliers.delete"><Button variant="ghost" className="text-ember-600" aria-label={t("Delete supplier")} onClick={() => setDeleting(true)}><Trash2 className="size-4" /></Button></Can>
          </>
        }
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3">
        <Stat label={owed >= 0 ? t("We owe") : t("Supplier owes us")} value={money(Math.abs(owed))} tone={owed > 0 ? "ember" : "default"} />
        <Stat label={t("Unpaid invoices")} value={unpaid_purchases.length} />
        <Stat label={t("Payment terms")} value={`${s.payment_terms_days} days`} />
      </div>
      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <Panel padded={false}>
          <div className="px-3 pt-2"><Tabs value={tab} onChange={setTab} tabs={[{ value: "purchases", label: t("Purchases") }, ...(can("suppliers.statement") ? [{ value: "statement" as const, label: t("Statement") }] : []), { value: "payments", label: t("Payments") }]} /></div>
          {tab === "purchases" && (
            <>
              {unpaid_purchases.length > 0 && (
                <div className="border-b border-ink-100">
                  <p className="px-4 pt-3 text-sm font-semibold text-ember-700">{t("Unpaid")}</p>
                  <DataTable rows={unpaid_purchases} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/purchases/${r.id}`)} columns={[
                    { key: "number", header: t("Purchase") }, { key: "supplier_invoice_no", header: t("Their invoice") },
                    { key: "purchase_date", header: t("Date"), render: (r) => date(r.purchase_date) }, { key: "due_date", header: t("Due"), render: (r) => date(r.due_date) },
                    { key: "balance", header: t("Balance"), align: "right", render: (r) => <span className="font-semibold">{money(r.balance)}</span> },
                  ]} />
                </div>
              )}
              <DataTable rows={recent_purchases} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/purchases/${r.id}`)} empty={{ title: t("No purchases yet") }} columns={[
                { key: "number", header: t("Purchase") }, { key: "purchase_date", header: t("Date"), render: (r) => date(r.purchase_date) },
                { key: "supplier_total", header: t("Amount"), align: "right", render: (r) => money(r.supplier_total) },
                { key: "payment_status", header: t("Payment"), render: (r) => <StatusBadge status={r.payment_status} /> },
              ]} />
            </>
          )}
          {tab === "statement" && (
            <div>
              <div className="flex flex-wrap items-end gap-3 border-b border-ink-100 p-3">
                <Field label={t("From")}>{(p) => <Input {...p} type="date" value={range.date_from} onChange={(e) => setRange({ ...range, date_from: e.target.value })} />}</Field>
                <Field label={t("To")}>{(p) => <Input {...p} type="date" value={range.date_to} onChange={(e) => setRange({ ...range, date_to: e.target.value })} />}</Field>
              </div>
              {statement.data && (
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-ink-200 bg-ink-50/60 text-left text-[12.5px] text-ink-600"><th className="px-3 py-2">{t("Date")}</th><th className="px-3 py-2">{t("Reference")}</th><th className="px-3 py-2">{t("Description")}</th><th className="px-3 py-2 text-right">{t("Bought")}</th><th className="px-3 py-2 text-right">{t("Paid")}</th><th className="px-3 py-2 text-right">{t("We owe")}</th></tr></thead>
                  <tbody>
                    <tr className="border-b border-ink-100 text-ink-600"><td className="px-3 py-2" colSpan={5}>{t("Opening balance")}</td><td className="num px-3 py-2 text-right">{money(statement.data.data.opening_balance)}</td></tr>
                    {statement.data.data.entries.map((e, i) => (
                      <tr key={i} className="border-b border-ink-100"><td className="px-3 py-2">{date(e.date)}</td><td className="px-3 py-2">{e.reference}</td><td className="px-3 py-2">{e.description}</td><td className="num px-3 py-2 text-right">{e.credit ? money(e.credit) : ""}</td><td className="num px-3 py-2 text-right">{e.debit ? money(e.debit) : ""}</td><td className="num px-3 py-2 text-right font-medium">{money(e.balance)}</td></tr>
                    ))}
                    <tr className="font-semibold"><td className="px-3 py-2" colSpan={5}>{t("Closing balance")}</td><td className="num px-3 py-2 text-right">{money(statement.data.data.closing_balance)}</td></tr>
                  </tbody>
                </table>
              )}
            </div>
          )}
          {tab === "payments" && (
            <DataTable rows={recent_payments} rowKey={(r) => r.id} empty={{ title: t("No payments yet") }} columns={[
              { key: "number", header: t("Payment") }, { key: "paid_on", header: t("Date"), render: (r) => date(r.paid_on) },
              { key: "method", header: t("Method"), render: (r) => humanize(r.method) }, { key: "reference", header: t("Reference") },
              { key: "amount", header: t("Amount"), align: "right", render: (r) => money(r.amount) },
            ]} />
          )}
        </Panel>
        <Panel title={t("Details")}><DescriptionList items={[["Contact", s.contact_person], ["Email", s.email], ["Address", s.address], ["TIN", s.tin], ["Notes", s.notes]]} /></Panel>
      </div>

      <Modal open={paying} onClose={() => setPaying(false)} title={t("Pay {name}", { name: s.name })} description={t("Applied to the oldest unpaid purchases first.")} size="sm"
        footer={<><Button variant="secondary" onClick={() => setPaying(false)}>{t("Cancel")}</Button><Button loading={payAction.isPending} onClick={() => payAction.mutate(undefined)}>{t("Record payment")}</Button></>}>
        <div className="space-y-4">
          <Field label={t("Amount")} required error={payErrors.amount}>{(p) => <Input {...p} type="number" min={1} className="num text-lg font-semibold" value={pay.amount} onChange={(e) => setPay({ ...pay, amount: Number(e.target.value) })} />}</Field>
          <Field label={t("Paid by")}>{(p) => <Select {...p} value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}><option value="bank">{t("Bank")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="cash">{t("Cash")}</option><option value="cheque">{t("Cheque")}</option><option value="lipa_namba">{t("Lipa namba")}</option></Select>}</Field>
          <Field label={t("Reference")}>{(p) => <Input {...p} value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} />}</Field>
          <Field label={t("Paid on")} error={payErrors.paid_on}>{(p) => <Input {...p} type="date" max={today()} value={pay.paid_on} onChange={(e) => setPay({ ...pay, paid_on: e.target.value })} />}</Field>
        </div>
      </Modal>
      {editing && <SupplierForm open={editing} onClose={() => setEditing(false)} supplier={s} />}
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} onConfirm={() => remove.mutate(undefined)} loading={remove.isPending} title={t("Delete {name}?", { name: s.name })} message={t("The supplier is moved to deleted records. Purchase history is kept.")} confirmLabel={t("Delete supplier")} />
    </Guard>
  );
}
