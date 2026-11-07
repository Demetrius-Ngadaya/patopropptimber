"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { PiggyBank, Plus } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { paymentMethodLabels } from "@/lib/lookups";
import type { Customer } from "@/lib/types";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, Stat, Tabs } from "@/components/ui";
import { CustomerPicker } from "@/features/crm/components/CustomerPicker";
import { MONEY_METHODS, type SavingsAccount } from "@/features/savings/types";

type Summary = { accounts: number; balance: number; total_deposited: number; total_spent: number; total_withdrawn: number };
const statusTone = { active: "green", frozen: "saffron", closed: "neutral" } as const;

function OpenAccountDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const router = useRouter();
  const [mode, setMode] = useState<"existing" | "new">("existing");
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [form, setForm] = useState({ name: "", phone: "", address: "", initial_deposit: "", method: "cash", reference: "" });
  const save = useAction(
    () => api.post<ApiResponse<SavingsAccount>>("admin/savings", {
      customer_id: mode === "existing" ? customer?.id : undefined,
      customer: mode === "new" ? { name: form.name, phone: form.phone, address: form.address } : undefined,
      initial_deposit: Number(form.initial_deposit) || undefined,
      method: Number(form.initial_deposit) ? form.method : undefined,
      reference: form.reference || undefined,
    }),
    { invalidate: [["admin/savings"]], onSuccess: (res) => { onClose(); router.push(`/admin/savings/${res.data.id}`); } },
  );
  const e = fieldErrors(save.error);
  const valid = mode === "existing" ? Boolean(customer) : form.name.trim() && form.phone.trim() && form.address.trim();

  return (
    <Modal open={open} onClose={onClose} title={t("Open a savings account")} description={t("The customer deposits money over time and later buys with it.")}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={!valid} onClick={() => save.mutate(undefined)}>{t("Open account")}</Button></>}>
      <div className="space-y-4">
        <Tabs<"existing" | "new"> value={mode} onChange={setMode} tabs={[{ value: "existing", label: t("Registered customer") }, { value: "new", label: t("New customer") }]} />
        {mode === "existing" ? (
          <Field label={t("Customer")} required>{() => <CustomerPicker value={customer} onChange={setCustomer} />}</Field>
        ) : (
          <>
            <Field label={t("Full name")} required error={e["customer.name"]}>{(p) => <Input {...p} value={form.name} onChange={(ev) => setForm({ ...form, name: ev.target.value })} />}</Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t("Phone number")} required error={e["customer.phone"]}>{(p) => <Input {...p} type="tel" placeholder="0712 345 678" value={form.phone} onChange={(ev) => setForm({ ...form, phone: ev.target.value })} />}</Field>
              <Field label={t("Residential address")} required error={e["customer.address"]}>{(p) => <Input {...p} value={form.address} onChange={(ev) => setForm({ ...form, address: ev.target.value })} />}</Field>
            </div>
          </>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t("First deposit (optional)")} error={e.initial_deposit}>{(p) => <Input {...p} type="number" min={0} step="any" className="num text-right" value={form.initial_deposit} onChange={(ev) => setForm({ ...form, initial_deposit: ev.target.value })} />}</Field>
          {Number(form.initial_deposit) > 0 && (
            <Field label={t("Paid with")}>{(p) => <Select {...p} value={form.method} onChange={(ev) => setForm({ ...form, method: ev.target.value })}>{MONEY_METHODS.map((m) => <option key={m} value={m}>{t(paymentMethodLabels[m] ?? m)}</option>)}</Select>}</Field>
          )}
        </div>
        {Number(form.initial_deposit) > 0 && form.method !== "cash" && (
          <Field label={t("Transaction ID")}>{(p) => <Input {...p} value={form.reference} onChange={(ev) => setForm({ ...form, reference: ev.target.value })} />}</Field>
        )}
      </div>
    </Modal>
  );
}

export default function SavingsPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const [status, setStatus] = useState<"" | "active" | "frozen" | "closed">("active");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [opening, setOpening] = useState(false);
  const q = useDebounced(search);
  const list = useApi<Paginated<SavingsAccount> & { summary: Summary }>("admin/savings", { status, search: q, page });
  const s = list.data?.summary;

  return (
    <Guard perm="savings.view">
      <PageHeader
        title={t("Customer savings")}
        description={t("Money customers deposit bit by bit to buy later. Every deposit, purchase and withdrawal updates the balance automatically.")}
        actions={can("savings.open") && <Button icon={<Plus className="size-4" />} onClick={() => setOpening(true)}>{t("Open account")}</Button>}
      />
      {s && (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label={t("Savings held")} value={money(s.balance)} tone="brand" sub={t("{count} account(s)", { count: s.accounts })} />
          <Stat label={t("Total deposited")} value={money(s.total_deposited)} />
          <Stat label={t("Spent on purchases")} value={money(s.total_spent)} />
          <Stat label={t("Paid back out")} value={money(s.total_withdrawn)} />
        </div>
      )}
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[{ value: "active", label: t("Active") }, { value: "frozen", label: t("Frozen") }, { value: "closed", label: t("Closed") }, { value: "", label: t("All") }]} />
        </div>
        <div className="border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Account number, name or phone")} className="w-full max-w-sm" />
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/savings/${r.id}`)}
          columns={[
            { key: "customer", header: t("Customer"), render: (r) => <span className="font-medium text-ink-900">{r.customer?.name}<span className="block text-xs font-normal text-ink-500">{r.account_no} · {r.customer?.phone}</span></span> },
            { key: "total_deposited", header: t("Deposited"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.total_deposited)}</span> },
            { key: "total_spent", header: t("Spent"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.total_spent)}</span> },
            { key: "balance", header: t("Balance"), align: "right", render: (r) => <span className="num font-semibold text-brand-700">{money(r.balance)}</span> },
            { key: "owes", header: t("Owes"), align: "right", hideOnMobile: true, render: (r) => toNumber(r.customer?.balance) > 0 ? <span className="num text-ember-600">{money(r.customer?.balance)}</span> : "—" },
            { key: "last", header: t("Last activity"), hideOnMobile: true, render: (r) => r.last_transaction_at ? dateTime(r.last_transaction_at) : "—" },
            { key: "status", header: t("Status"), render: (r) => <Badge tone={statusTone[r.status]}>{t(r.status === "active" ? "Active" : r.status === "frozen" ? "Frozen" : "Closed")}</Badge> },
          ]}
          empty={{ title: t("No savings accounts"), message: t("Open an account when a customer wants to save with you."), action: can("savings.open") ? <Button icon={<PiggyBank className="size-4" />} onClick={() => setOpening(true)}>{t("Open account")}</Button> : undefined }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      <OpenAccountDialog open={opening} onClose={() => setOpening(false)} />
    </Guard>
  );
}
