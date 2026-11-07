"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useState } from "react";
import { ArrowLeft, BellRing, Download, KeyRound, Pencil, Trash2, Wallet } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money, monthStart, toNumber, today } from "@/lib/format";
import { fieldErrors, useAction, useApi, useUrlParam } from "@/lib/hooks";
import { paymentMethodLabels, useLookups } from "@/lib/lookups";
import type { Customer, Payment } from "@/lib/types";
import { FinancialHistory } from "@/features/crm/components/FinancialHistory";
import { Guard, Can } from "@/components/layout/Guard";
import { CustomerForm } from "@/features/crm/components/CustomerForm";
import { Badge, Button, ConfirmDialog, DataTable, DescriptionList, ErrorState, Field, Input, Modal, PageHeader, Panel, Select, Spinner, Stat, StatusBadge, Switch, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { SendDialog } from "@/features/messaging/SendDialog";

type Detail = {
  customer: Customer & { address?: string; city?: string; tin?: string; notes?: string; branch?: { name: string } | null; user?: Customer["user"] & { email?: string | null; phone?: string | null } };
  unpaid_sales: Array<{ id: number; number: string; sold_at: string; total: string; amount_paid: string; balance: string; due_date: string | null }>;
  recent_sales: Array<{ id: number; number: string; sold_at: string; total: string; balance: string; payment_status: string; status: string; channel: string }>;
  recent_payments: Payment[];
  orders: Array<{ id: number; number: string; status: string; total: string; created_at: string }>;
  stats: { total_purchases: number; invoices: number };
};

type Statement = { opening_balance: number; closing_balance: number; total_debit: number; total_credit: number; entries: Array<{ date: string; reference: string; description: string; debit: number; credit: number; balance: number }> };

function PaymentForm({ customer, open, onClose, saleId }: { customer: Customer; open: boolean; onClose: () => void; saleId?: number }) {
  const t = useT();
  const { payment } = useLookups(["payment"]);
  const [form, setForm] = useState({ amount: toNumber(customer.balance), method: "cash", provider: "", reference: "", notes: "" });
  const pay = useAction(
    () => api.post(`admin/customers/${customer.id}/payments`, { ...form, provider: form.provider || undefined, reference: form.reference || undefined, sale_id: saleId }),
    { invalidate: [[`admin/customers/${customer.id}`], ["admin/customers"], ["admin/sales"]], onSuccess: onClose },
  );
  const errors = fieldErrors(pay.error);
  return (
    <Modal open={open} onClose={onClose} title={t("Receive payment from {name}", { name: customer.name })} description={t("Owes {balance}. The money is applied to the oldest unpaid receipts first.", { balance: money(customer.balance) })} size="sm"
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={pay.isPending} onClick={() => pay.mutate(undefined)} disabled={!form.amount}>{t("Record payment")}</Button></>}>
      <div className="space-y-4">
        <Field label={t("Amount")} required error={errors.amount}>
          {(p) => <Input {...p} type="number" min={1} max={toNumber(customer.balance)} className="num text-lg font-semibold" value={form.amount} onChange={(e) => setForm({ ...form, amount: Number(e.target.value) })} />}
        </Field>
        <Field label={t("Paid by")} required>
          {(p) => (
            <Select {...p} value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })}>
              {(payment?.methods ?? ["cash", "mobile_money", "bank"]).filter((m) => m !== "credit_note").map((m) => <option key={m} value={m}>{t(paymentMethodLabels[m] ?? m)}</option>)}
            </Select>
          )}
        </Field>
        {form.method !== "cash" && (
          <div className="grid grid-cols-2 gap-3">
            <Field label={t("Network / bank")}>
              {(p) => (
                <Select {...p} value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })}>
                  <option value="">{t("Choose…")}</option>
                  {payment?.providers.map((x) => <option key={x}>{x}</option>)}
                </Select>
              )}
            </Field>
            <Field label={t("Transaction ID")} required={["mobile_money", "bank", "cheque"].includes(form.method)} error={errors.reference}>
              {(p) => <Input {...p} value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} />}
            </Field>
          </div>
        )}
      </div>
    </Modal>
  );
}

function AccountForm({ detail, open, onClose }: { detail: Detail; open: boolean; onClose: () => void }) {
  const t = useT();
  const c = detail.customer;
  const [form, setForm] = useState({ username: c.user?.username ?? "", email: c.user?.email ?? c.email ?? "", phone: c.user?.phone ?? c.phone ?? "", password: "", password_confirmation: "", is_active: c.user?.is_active ?? true });
  const save = useAction(
    () => api.put(`admin/customers/${c.id}/account`, { ...form, username: form.username || undefined, email: form.email || undefined, phone: form.phone || undefined, password: form.password || undefined, password_confirmation: form.password_confirmation || undefined }),
    { invalidate: [[`admin/customers/${c.id}`]], onSuccess: onClose },
  );
  const errors = fieldErrors(save.error);
  return (
    <Modal open={open} onClose={onClose} title={c.user ? t("Online account") : t("Create online account")} description={t("Lets the customer log in to the shop to order and see their account.")} size="md"
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{c.user ? t("Save account") : t("Create account")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Username")} error={errors.username} hint={t("Leave empty to create one automatically.")}>
          {(p) => <Input {...p} value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} />}
        </Field>
        <Field label={t("Login phone")} error={errors.phone}>
          {(p) => <Input {...p} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />}
        </Field>
        <Field label={t("Email")} error={errors.email} className="sm:col-span-2">
          {(p) => <Input {...p} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />}
        </Field>
        <Field label={c.user ? t("New password (optional)") : t("Password")} required={!c.user} error={errors.password} hint={t("They will be asked to change it at first login.")}>
          {(p) => <Input {...p} type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />}
        </Field>
        <Field label={t("Repeat password")}>
          {(p) => <Input {...p} type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />}
        </Field>
        {c.user && <div className="sm:col-span-2"><Switch label={t("Account active")} description={t("Turning this off logs the customer out immediately.")} checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} /></div>}
      </div>
    </Modal>
  );
}

export default function CustomerDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const router = useRouter();
  const { can } = useAuth();
  const q = useApi<ApiResponse<Detail>>(`admin/customers/${id}`);
  const [tab, setTab] = useState<"sales" | "finance" | "statement" | "payments" | "orders">("sales");
  useUrlParam("tab", (v) => v === "finance" && setTab("finance"));
  const [range, setRange] = useState({ date_from: monthStart(), date_to: today() });
  const statement = useApi<ApiResponse<Statement>>(tab === "statement" ? `admin/customers/${id}/statement` : null, range);
  const [paying, setPaying] = useState(false);
  const [editing, setEditing] = useState(false);
  const [account, setAccount] = useState(false);
  const [deleting, setDeleting] = useState(false);
  useUrlParam("pay", () => setPaying(true));
  const [reminding, setReminding] = useState(false);
  const remove = useAction(() => api.del(`admin/customers/${id}`), { invalidate: [["admin/customers"]], onSuccess: () => router.replace("/admin/customers") });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Customer not found.")} />;
  const d = q.data.data;
  const c = d.customer;
  const owes = toNumber(c.balance) > 0;

  return (
    <Guard perm="customers.view">
      <PageHeader
        back={<Link href="/admin/customers" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Customers")}</Link>}
        title={c.name}
        description={<span className="flex flex-wrap items-center gap-2">{c.code}{c.phone && <span>{c.phone}</span>}{c.allow_credit && <Badge tone="blue">{t("Credit customer")}</Badge>}{!c.is_active && <Badge tone="red">{t("Inactive")}</Badge>}</span>}
        actions={
          <>
            {owes && <Can perm="sales.receive_payment"><Button icon={<Wallet className="size-4" />} onClick={() => setPaying(true)}>{t("Receive payment")}</Button></Can>}
            {owes && (c.phone || c.email) && <Can perm="customers.remind"><Button variant="secondary" icon={<BellRing className="size-4" />} onClick={() => setReminding(true)}>{t("Send reminder")}</Button></Can>}
            <Can perm="customers.update"><Button variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>{t("Edit")}</Button></Can>
            <Can perm="customers.manage_account"><Button variant="secondary" icon={<KeyRound className="size-4" />} onClick={() => setAccount(true)}>{c.user ? t("Online account") : t("Create login")}</Button></Can>
            <Can perm="customers.delete"><Button variant="ghost" className="text-ember-600" aria-label={t("Delete customer")} onClick={() => setDeleting(true)}><Trash2 className="size-4" /></Button></Can>
          </>
        }
      />

      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={t("Owes")} value={money(c.balance)} tone={owes ? "ember" : "default"} sub={toNumber(c.credit_limit) > 0 ? `Limit ${money(c.credit_limit)}` : c.allow_credit ? "No limit" : "Cash only"} />
        <Stat label={t("Total bought")} value={money(d.stats.total_purchases)} sub={`${d.stats.invoices} receipts`} />
        <Stat label={t("Unpaid receipts")} value={d.unpaid_sales.length} />
        <Stat label={t("Online account")} value={c.user ? (c.user.is_active ? "Active" : "Disabled") : "None"} sub={c.user?.last_login_at ? `Last login ${date(c.user.last_login_at)}` : undefined} />
      </div>

      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <Panel padded={false}>
          <div className="px-3 pt-2">
            <Tabs value={tab} onChange={setTab} tabs={[{ value: "sales", label: t("Unpaid & recent sales") }, ...(can(["savings.view", "credit.view", "customers.statement"]) ? [{ value: "finance" as const, label: t("Savings & debts") }] : []), ...(can("customers.statement") ? [{ value: "statement" as const, label: t("Statement") }] : []), { value: "payments", label: t("Payments") }, { value: "orders", label: t("Orders") }]} />
          </div>
          {tab === "sales" && (
            <>
              {d.unpaid_sales.length > 0 && (
                <div className="border-b border-ink-100">
                  <p className="px-4 pt-3 text-sm font-semibold text-ember-700">{t("Unpaid")}</p>
                  <DataTable rows={d.unpaid_sales} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/sales/${r.id}`)} columns={[
                    { key: "number", header: t("Receipt") },
                    { key: "sold_at", header: t("Date"), render: (r) => date(r.sold_at) },
                    { key: "due_date", header: t("Due"), render: (r) => <span className={r.due_date && r.due_date < today() ? "font-medium text-ember-600" : undefined}>{date(r.due_date)}</span> },
                    { key: "total", header: t("Total"), align: "right", render: (r) => money(r.total) },
                    { key: "balance", header: t("Balance"), align: "right", render: (r) => <span className="font-semibold">{money(r.balance)}</span> },
                  ]} />
                </div>
              )}
              <DataTable rows={d.recent_sales} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/sales/${r.id}`)} empty={{ title: t("No sales yet") }} columns={[
                { key: "number", header: t("Receipt") },
                { key: "sold_at", header: t("Date"), render: (r) => dateTime(r.sold_at) },
                { key: "channel", header: t("Channel"), render: (r) => <span className="capitalize">{r.channel}</span> },
                { key: "total", header: t("Total"), align: "right", render: (r) => money(r.total) },
                { key: "payment_status", header: t("Payment"), render: (r) => <StatusBadge status={r.status === "cancelled" ? "cancelled" : r.payment_status} /> },
              ]} />
            </>
          )}
          {tab === "finance" && <FinancialHistory customerId={Number(id)} />}
          {tab === "statement" && (
            <div>
              <div className="flex flex-wrap items-end gap-3 border-b border-ink-100 p-3">
                <Field label={t("From")}>{(p) => <Input {...p} type="date" value={range.date_from} onChange={(e) => setRange({ ...range, date_from: e.target.value })} />}</Field>
                <Field label={t("To")}>{(p) => <Input {...p} type="date" value={range.date_to} onChange={(e) => setRange({ ...range, date_to: e.target.value })} />}</Field>
                <Button variant="secondary" icon={<Download className="size-4" />} onClick={() => window.print()}>{t("Print")}</Button>
              </div>
              {statement.data && (
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-ink-200 bg-ink-50/60 text-left text-[12.5px] text-ink-600"><th className="px-3 py-2">{t("Date")}</th><th className="px-3 py-2">{t("Reference")}</th><th className="px-3 py-2">{t("Description")}</th><th className="px-3 py-2 text-right">{t("Bought")}</th><th className="px-3 py-2 text-right">{t("Paid")}</th><th className="px-3 py-2 text-right">{t("Balance")}</th></tr></thead>
                  <tbody>
                    <tr className="border-b border-ink-100 text-ink-600"><td className="px-3 py-2" colSpan={5}>{t("Opening balance")}</td><td className="num px-3 py-2 text-right">{money(statement.data.data.opening_balance)}</td></tr>
                    {statement.data.data.entries.map((e, i) => (
                      <tr key={i} className="border-b border-ink-100">
                        <td className="px-3 py-2 whitespace-nowrap">{date(e.date)}</td><td className="px-3 py-2">{e.reference}</td><td className="px-3 py-2">{e.description}</td>
                        <td className="num px-3 py-2 text-right">{e.debit ? money(e.debit) : ""}</td><td className="num px-3 py-2 text-right">{e.credit ? money(e.credit) : ""}</td><td className="num px-3 py-2 text-right font-medium">{money(e.balance)}</td>
                      </tr>
                    ))}
                    <tr className="font-semibold"><td className="px-3 py-2" colSpan={3}>{t("Closing balance")}</td><td className="num px-3 py-2 text-right">{money(statement.data.data.total_debit)}</td><td className="num px-3 py-2 text-right">{money(statement.data.data.total_credit)}</td><td className="num px-3 py-2 text-right">{money(statement.data.data.closing_balance)}</td></tr>
                  </tbody>
                </table>
              )}
            </div>
          )}
          {tab === "payments" && (
            <DataTable rows={d.recent_payments} rowKey={(r) => r.id} empty={{ title: t("No payments yet") }} columns={[
              { key: "number", header: t("Receipt") },
              { key: "paid_at", header: t("Date"), render: (r) => dateTime(r.paid_at) },
              { key: "method", header: t("Method"), render: (r) => `${t(paymentMethodLabels[r.method] ?? r.method)}${r.provider ? ` (${r.provider})` : ""}` },
              { key: "reference", header: t("Reference") },
              { key: "amount", header: t("Amount"), align: "right", render: (r) => money(r.amount) },
            ]} />
          )}
          {tab === "orders" && (
            <DataTable rows={d.orders} rowKey={(r) => r.id} onRowClick={(r) => router.push(`/admin/orders/${r.id}`)} empty={{ title: t("No orders yet") }} columns={[
              { key: "number", header: t("Order") },
              { key: "created_at", header: t("Date"), render: (r) => dateTime(r.created_at) },
              { key: "status", header: t("Status"), render: (r) => <StatusBadge status={r.status} /> },
              { key: "total", header: t("Total"), align: "right", render: (r) => money(r.total) },
            ]} />
          )}
        </Panel>
        <Panel title={t("Details")}>
          <DescriptionList items={[["Email", c.email], ["Address", [c.address, c.city].filter(Boolean).join(", ") || null], ["TIN", c.tin], ["Type", <span key="t" className="capitalize">{c.customer_type}</span>], ["Registered at", c.branch?.name ?? "Online"], ["Notes", c.notes]]} />
        </Panel>
      </div>

      {paying && <PaymentForm customer={c} open={paying} onClose={() => setPaying(false)} />}
      <CustomerForm open={editing} onClose={() => setEditing(false)} customer={c} />
      {account && <AccountForm detail={d} open={account} onClose={() => setAccount(false)} />}
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} onConfirm={() => remove.mutate(undefined)} loading={remove.isPending} title={t("Delete {name}?", { name: c.name })} message={t("The customer and their online login are moved to deleted records. Their sales history is kept. You can restore them later.")} confirmLabel={t("Delete customer")} />
      <SendDialog
        open={reminding}
        onClose={() => setReminding(false)}
        title={t("Remind {name} to pay", { name: c.name })}
        description={t("Email also gets a statement of every unpaid invoice as a PDF.")}
        endpoint={`admin/customers/${c.id}/remind`}
        phone={c.phone}
        email={c.email}
        defaults={["sms", "whatsapp", "email"]}
      />
    </Guard>
  );
}
