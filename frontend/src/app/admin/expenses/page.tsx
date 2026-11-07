"use client";

import { useState } from "react";
import { Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, monthStart, today, humanize } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { paymentMethodLabels, useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { Button, Checkbox, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, Stat, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Expense = { id: number; number: string; title: string; description: string | null; amount: string; payment_method: string; reference: string | null; expense_date: string; expense_category_id: number | null; category?: { name: string } | null; creator?: { name: string } | null; deleted_at?: string | null };
const blank = { title: "", expense_category_id: "", description: "", amount: "", payment_method: "cash", reference: "", expense_date: today() };

export default function ExpensesPage() {
  const t = useT();
  const { can } = useAuth();
  const { expense_categories = [] } = useLookups(["expense_categories"]);
  const [f, setF] = useState({ search: "", expense_category_id: "", date_from: monthStart(), date_to: today() });
  const [trashed, setTrashed] = useState(false);
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Expense | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(blank);
  const [deleting, setDeleting] = useState<Expense | null>(null);
  const search = useDebounced(f.search);
  const list = useApi<Paginated<Expense> & { summary: { total: number } }>("admin/expenses", { ...f, search, page, trashed: trashed ? "only" : undefined });

  const save = useAction(
    () => {
      const body = { ...form, expense_category_id: form.expense_category_id || null, amount: Number(form.amount) };
      return editing ? api.put<ApiResponse<Expense>>(`admin/expenses/${editing.id}`, body) : api.post<ApiResponse<Expense>>("admin/expenses", body);
    },
    { invalidate: [["admin/expenses"]], onSuccess: () => setOpen(false) },
  );
  const remove = useAction((v: { id: number; reason: string }) => api.del(`admin/expenses/${v.id}`, { reason: v.reason }), { invalidate: [["admin/expenses"]], onSuccess: () => setDeleting(null) });
  const restore = useAction((id: number) => api.post(`admin/expenses/${id}/restore`), { invalidate: [["admin/expenses"]] });
  const errors = fieldErrors(save.error);

  const openForm = (e?: Expense) => {
    save.reset();
    setEditing(e ?? null);
    setForm(e ? { title: e.title, expense_category_id: e.expense_category_id ? String(e.expense_category_id) : "", description: e.description ?? "", amount: e.amount, payment_method: e.payment_method, reference: e.reference ?? "", expense_date: e.expense_date.slice(0, 10) } : blank);
    setOpen(true);
  };

  return (
    <Guard perm="expenses.view">
      <PageHeader title={t("Expenses")} description={t("Money spent running the business: rent, power, wages, transport…")} actions={can("expenses.create") && <Button icon={<Plus className="size-4" />} onClick={() => openForm()}>{t("Record expense")}</Button>} />
      {list.data?.summary && <div className="mb-5 max-w-xs"><Stat label={t("Total for the filtered period")} value={money(list.data.summary.total)} tone="saffron" /></div>}
      <Panel padded={false}>
        <div className="grid gap-3 border-b border-ink-100 p-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto]">
          <SearchInput value={f.search} onChange={(v) => setF({ ...f, search: v })} placeholder={t("Search expenses")} />
          <Select aria-label={t("Category")} value={f.expense_category_id} onChange={(e) => setF({ ...f, expense_category_id: e.target.value })}><option value="">{t("All categories")}</option>{expense_categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select>
          <Input aria-label={t("From")} type="date" value={f.date_from} onChange={(e) => setF({ ...f, date_from: e.target.value })} />
          <Input aria-label={t("To")} type="date" value={f.date_to} onChange={(e) => setF({ ...f, date_to: e.target.value })} />
          {can("expenses.restore") && <Checkbox label={t("Deleted")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} className="self-center" />}
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} columns={[
          { key: "expense_date", header: t("Date"), render: (r) => date(r.expense_date) },
          { key: "title", header: t("Expense"), render: (r) => <span className="font-medium">{r.title}{r.description && <span className="block text-xs font-normal text-ink-500">{r.description}</span>}</span> },
          { key: "category.name", header: t("Category"), hideOnMobile: true },
          { key: "payment_method", header: t("Paid by"), hideOnMobile: true, render: (r) => t(paymentMethodLabels[r.payment_method] ?? humanize(r.payment_method)) },
          { key: "creator.name", header: t("Recorded by"), hideOnMobile: true },
          { key: "amount", header: t("Amount"), align: "right", render: (r) => <span className="font-semibold">{money(r.amount)}</span> },
          { key: "_a", header: "", align: "right", render: (r) => r.deleted_at ? (can("expenses.restore") && <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => restore.mutate(r.id)}>{t("Restore")}</Button>) : (
            <span className="flex justify-end gap-1">
              {can("expenses.update") && <Button size="sm" variant="ghost" aria-label={t("Edit")} onClick={() => openForm(r)}><Pencil className="size-4" /></Button>}
              {can("expenses.delete") && <Button size="sm" variant="ghost" aria-label={t("Delete")} onClick={() => setDeleting(r)}><Trash2 className="size-4 text-ember-600" /></Button>}
            </span>
          ) },
        ]} empty={{ title: t("No expenses in this period") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>

      <Modal open={open} onClose={() => setOpen(false)} title={editing ? t("Edit expense") : t("Record expense")}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{editing ? t("Save changes") : t("Record expense")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("What was it for?")} required error={errors.title} className="sm:col-span-2">{(p) => <Input {...p} placeholder={t("e.g. Electricity (LUKU)")} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />}</Field>
          <Field label={t("Amount")} required error={errors.amount}>{(p) => <Input {...p} type="number" min={0} step="any" className="num" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />}</Field>
          <Field label={t("Date")} required error={errors.expense_date}>{(p) => <Input {...p} type="date" max={today()} value={form.expense_date} onChange={(e) => setForm({ ...form, expense_date: e.target.value })} />}</Field>
          <Field label={t("Category")}>{(p) => <Select {...p} value={form.expense_category_id} onChange={(e) => setForm({ ...form, expense_category_id: e.target.value })}><option value="">{t("Uncategorised")}</option>{expense_categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select>}</Field>
          <Field label={t("Paid by")}>{(p) => <Select {...p} value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value })}><option value="cash">{t("Cash")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="bank">{t("Bank")}</option><option value="lipa_namba">{t("Lipa namba")}</option><option value="cheque">{t("Cheque")}</option><option value="card">{t("Card")}</option></Select>}</Field>
          <Field label={t("Reference / receipt no.")} className="sm:col-span-2">{(p) => <Input {...p} value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} />}</Field>
          <Field label={t("Details")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />}</Field>
        </div>
      </Modal>
      <ConfirmDialog open={Boolean(deleting)} onClose={() => setDeleting(null)} onConfirm={(reason) => deleting && remove.mutate({ id: deleting.id, reason })} loading={remove.isPending} title={t("Delete this expense?")} message={t("{title} ({amount}) will be moved to deleted records.", { title: deleting?.title, amount: money(deleting?.amount) })} confirmLabel={t("Delete expense")} requireReason />
    </Guard>
  );
}
