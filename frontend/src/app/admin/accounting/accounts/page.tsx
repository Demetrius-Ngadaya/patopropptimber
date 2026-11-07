"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { Lock, Pencil, Plus } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { cn, money } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, Field, Input, Modal, PageHeader, Panel, SearchInput, Select, Spinner, Textarea } from "@/components/ui";
import { ACCOUNT_TYPE_LABELS, ACCOUNT_TYPES, type Account, type AccountType } from "@/features/accounting/types";

type Form = { code: string; name: string; type: AccountType; sub_type: string; parent_id: string; is_header: boolean; allow_manual: boolean; is_active: boolean; description: string; bank_name: string; account_number: string };
const SUB_TYPES: Record<AccountType, string[]> = {
  asset: ["cash", "bank", "mobile_money", "receivable", "inventory", "other_current_asset", "fixed_asset"],
  liability: ["payable", "tax", "payroll", "customer_deposit", "long_term_liability"],
  equity: ["equity"],
  income: ["revenue", "other_income"],
  expense: ["cost_of_sales", "operating", "payroll"],
};

function AccountForm({ account, accounts, open, onClose }: { account: Account | null; accounts: Account[]; open: boolean; onClose: () => void }) {
  const t = useT();
  const init = (): Form => account
    ? { code: account.code, name: account.name, type: account.type, sub_type: account.sub_type ?? "", parent_id: account.parent_id ? String(account.parent_id) : "", is_header: account.is_header, allow_manual: account.allow_manual, is_active: account.is_active, description: account.description ?? "", bank_name: account.bank_name ?? "", account_number: account.account_number ?? "" }
    : { code: "", name: "", type: "expense", sub_type: "operating", parent_id: "", is_header: false, allow_manual: true, is_active: true, description: "", bank_name: "", account_number: "" };
  const [form, setForm] = useState<Form>(init);
  useEffect(() => {
    if (open) setForm(init());
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the dialog opens
  }, [open, account]);

  const save = useAction(() => {
    const body = { ...form, parent_id: form.parent_id ? Number(form.parent_id) : null, sub_type: form.sub_type || null };
    return account ? api.put(`admin/accounting/accounts/${account.id}`, body) : api.post("admin/accounting/accounts", body);
  }, { invalidate: [["admin/accounting/accounts"]], onSuccess: onClose });
  const e = fieldErrors(save.error);
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }));
  const system = Boolean(account?.system_key);

  return (
    <Modal open={open} onClose={onClose} title={account ? t("Edit account") : t("New account")} description={system ? t("System account: used by automatic postings. You can rename it, but not change its type.") : undefined}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Code")} required error={e.code}>{(p) => <Input {...p} value={form.code} onChange={(ev) => set("code", ev.target.value)} placeholder="6250" />}</Field>
        <Field label={t("Name")} required error={e.name}>{(p) => <Input {...p} value={form.name} onChange={(ev) => set("name", ev.target.value)} />}</Field>
        <Field label={t("Type")} required error={e.type}>{(p) => <Select {...p} disabled={system} value={form.type} onChange={(ev) => { const ty = ev.target.value as AccountType; setForm((f) => ({ ...f, type: ty, sub_type: SUB_TYPES[ty][0], parent_id: "" })); }}>{ACCOUNT_TYPES.map((ty) => <option key={ty} value={ty}>{t(ACCOUNT_TYPE_LABELS[ty])}</option>)}</Select>}</Field>
        <Field label={t("Group")} error={e.sub_type}>{(p) => <Select {...p} disabled={system} value={form.sub_type} onChange={(ev) => set("sub_type", ev.target.value)}>{SUB_TYPES[form.type].map((s) => <option key={s} value={s}>{t(s.replace(/_/g, " "))}</option>)}</Select>}</Field>
        <Field label={t("Under heading")} error={e.parent_id} className="sm:col-span-2">
          {(p) => <Select {...p} value={form.parent_id} onChange={(ev) => set("parent_id", ev.target.value)}><option value="">{t("Top level")}</option>{accounts.filter((a) => a.type === form.type && a.is_header && a.id !== account?.id).map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</Select>}
        </Field>
        {["bank", "mobile_money"].includes(form.sub_type) && (
          <>
            <Field label={t("Bank / provider")}>{(p) => <Input {...p} value={form.bank_name} onChange={(ev) => set("bank_name", ev.target.value)} />}</Field>
            <Field label={t("Account / wallet number")}>{(p) => <Input {...p} value={form.account_number} onChange={(ev) => set("account_number", ev.target.value)} />}</Field>
          </>
        )}
        <Field label={t("Description")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={form.description} onChange={(ev) => set("description", ev.target.value)} />}</Field>
        <Checkbox label={t("Heading only (groups other accounts)")} disabled={system} checked={form.is_header} onChange={(ev) => set("is_header", ev.target.checked)} />
        <Checkbox label={t("Allow manual journals")} checked={form.allow_manual} onChange={(ev) => set("allow_manual", ev.target.checked)} />
        <Checkbox label={t("Active")} disabled={system} checked={form.is_active} onChange={(ev) => set("is_active", ev.target.checked)} />
      </div>
    </Modal>
  );
}

export default function AccountsPage() {
  const t = useT();
  const { can } = useAuth();
  const [search, setSearch] = useState("");
  const [inactive, setInactive] = useState(false);
  const [editing, setEditing] = useState<Account | null>(null);
  const [open, setOpen] = useState(false);
  const q = useApi<ApiResponse<Account[]>>("admin/accounting/accounts", { with_inactive: inactive ? 1 : undefined });
  const accounts = useMemo(() => q.data?.data ?? [], [q.data]);

  const depth = useMemo(() => {
    const byId = new Map(accounts.map((a) => [a.id, a]));
    const d = new Map<number, number>();
    for (const a of accounts) { let n = 0; let p = a.parent_id; while (p && byId.has(p) && n < 6) { n++; p = byId.get(p)!.parent_id; } d.set(a.id, n); }
    return d;
  }, [accounts]);
  const shown = accounts.filter((a) => !search || `${a.code} ${a.name}`.toLowerCase().includes(search.toLowerCase()));

  return (
    <Guard perm="accounting.view">
      <PageHeader title={t("Chart of accounts")} description={t("Every account in the books with its current balance. Click an account to see its ledger.")}
        actions={can("accounting.manage_accounts") && <Button icon={<Plus className="size-4" />} onClick={() => { setEditing(null); setOpen(true); }}>{t("New account")}</Button>} />
      <Panel padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={setSearch} placeholder={t("Code or name")} className="w-full max-w-xs" />
          <Checkbox label={t("Show inactive")} checked={inactive} onChange={(e) => setInactive(e.target.checked)} />
        </div>
        {q.isLoading ? <Spinner /> : ACCOUNT_TYPES.map((ty) => {
          const rows = shown.filter((a) => a.type === ty);
          if (!rows.length) return null;
          return (
            <section key={ty}>
              <h3 className="bg-ink-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-500">{t(ACCOUNT_TYPE_LABELS[ty])}</h3>
              <ul className="divide-y divide-ink-100">
                {rows.map((a) => (
                  <li key={a.id} className={cn("flex items-center gap-3 px-4 py-2 text-sm", !a.is_active && "opacity-50")}>
                    <span className="flex min-w-0 flex-1 items-center gap-2" style={{ paddingLeft: `${(depth.get(a.id) ?? 0) * 18}px` }}>
                      <span className="num w-14 shrink-0 text-ink-500">{a.code}</span>
                      {a.is_header ? <span className="font-semibold text-ink-900">{a.name}</span> : <Link href={`/admin/accounting/accounts/${a.id}`} className="truncate text-ink-900 hover:underline">{a.name}</Link>}
                      {a.system_key && <span title={t("System account")}><Lock className="size-3 text-ink-400" /></span>}
                      {!a.allow_manual && !a.is_header && <Badge>{t("automatic")}</Badge>}
                    </span>
                    <span className={cn("num shrink-0", a.is_header && "font-semibold")}>{money(a.balance)}</span>
                    {can("accounting.manage_accounts") && <Button size="sm" variant="ghost" aria-label={t("Edit")} onClick={() => { setEditing(a); setOpen(true); }}><Pencil className="size-3.5" /></Button>}
                  </li>
                ))}
              </ul>
            </section>
          );
        })}
      </Panel>
      <AccountForm account={editing} accounts={accounts} open={open} onClose={() => setOpen(false)} />
    </Guard>
  );
}
