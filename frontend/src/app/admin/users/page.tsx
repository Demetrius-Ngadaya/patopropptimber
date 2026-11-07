"use client";

import { useState } from "react";
import { KeyRound, ListChecks, LockOpen, LogOut, Pencil, Plus, RotateCcw, ShieldOff, Trash2 } from "lucide-react";
import Link from "next/link";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, Checkbox, ConfirmDialog, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, Switch } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Staff = { id: number; name: string; username: string; email: string | null; phone: string | null; role_id: number; branch_id: number | null; all_branches: boolean; is_active: boolean; must_change_password: boolean; last_login_at: string | null; locked_until: string | null; two_factor_confirmed_at?: string | null; role?: { name: string; is_super_admin: boolean }; branch?: { name: string } | null; deleted_at?: string | null };
type Role = { id: number; name: string; is_super_admin: boolean };
const blank = { name: "", username: "", email: "", phone: "", role_id: "", branch_id: "", all_branches: false, is_active: true, must_change_password: true, password: "", password_confirmation: "" };

export default function UsersPage() {
  const t = useT();
  const { can, user: me } = useAuth();
  const { branches = [] } = useLookups(["branches"]);
  const roles = useApi<ApiResponse<Role[]>>("admin/roles");
  const [search, setSearch] = useState("");
  const [roleId, setRoleId] = useState("");
  const [trashed, setTrashed] = useState(false);
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Staff | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(blank);
  const [resetting, setResetting] = useState<Staff | null>(null);
  const [pw, setPw] = useState({ password: "", password_confirmation: "" });
  const [deleting, setDeleting] = useState<Staff | null>(null);
  const q = useDebounced(search);
  const list = useApi<Paginated<Staff>>("admin/users", { search: q, role_id: roleId, page, trashed: trashed ? "only" : undefined });
  const inv = [["admin/users"]];

  const save = useAction(() => {
    const body = { ...form, role_id: Number(form.role_id), branch_id: form.branch_id ? Number(form.branch_id) : null, email: form.email || null, phone: form.phone || null, password: form.password || undefined, password_confirmation: form.password_confirmation || undefined };
    return editing ? api.put(`admin/users/${editing.id}`, body) : api.post("admin/users", body);
  }, { invalidate: inv, onSuccess: () => setOpen(false) });
  const reset = useAction(() => api.post(`admin/users/${resetting!.id}/reset-password`, { ...pw, must_change_password: true }), { invalidate: inv, onSuccess: () => setResetting(null) });
  const unlock = useAction((id: number) => api.post(`admin/users/${id}/unlock`), { invalidate: inv });
  const resetTwoFactor = useAction((id: number) => api.post(`admin/users/${id}/two-factor/reset`), { invalidate: inv });
  const forceLogout = useAction((id: number) => api.post(`admin/users/${id}/force-logout`));
  const remove = useAction((id: number) => api.del(`admin/users/${id}`), { invalidate: inv, onSuccess: () => setDeleting(null) });
  const restore = useAction((id: number) => api.post(`admin/users/${id}/restore`), { invalidate: inv });
  const errors = fieldErrors(save.error);

  const openForm = (u?: Staff) => {
    save.reset();
    setEditing(u ?? null);
    setForm(u ? { ...blank, name: u.name, username: u.username, email: u.email ?? "", phone: u.phone ?? "", role_id: String(u.role_id), branch_id: u.branch_id ? String(u.branch_id) : "", all_branches: u.all_branches, is_active: u.is_active, must_change_password: u.must_change_password } : blank);
    setOpen(true);
  };

  return (
    <Guard perm="users.view">
      <PageHeader title={t("Staff users")} description={t("Who can log in, their role and which branch they work in.")} actions={can("users.create") && <Button icon={<Plus className="size-4" />} onClick={() => openForm()}>{t("Add staff member")}</Button>} />
      <Panel padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={setSearch} placeholder={t("Name, username, email or phone")} className="w-full max-w-sm" />
          <Select aria-label={t("Role")} className="w-48" value={roleId} onChange={(e) => setRoleId(e.target.value)}><option value="">{t("All roles")}</option>{roles.data?.data.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select>
          {can("users.restore") && <Checkbox label={t("Show deleted")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} />}
        </div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} columns={[
          { key: "name", header: t("Name"), render: (r) => <span className="font-medium">{r.name}<span className="block text-xs font-normal text-ink-500">@{r.username}</span></span> },
          { key: "role.name", header: t("Role"), render: (r) => <Badge tone={r.role?.is_super_admin ? "navy" : "neutral"}>{r.role?.name}</Badge> },
          { key: "branch.name", header: t("Branch"), render: (r) => (r.all_branches ? "All branches" : r.branch?.name) },
          { key: "last_login_at", header: t("Last login"), hideOnMobile: true, render: (r) => dateTime(r.last_login_at) },
          { key: "2fa", header: t("Two-step"), hideOnMobile: true, render: (r) => (r.two_factor_confirmed_at ? <Badge tone="green">{t("On")}</Badge> : <Badge>{t("Off")}</Badge>) },
          { key: "is_active", header: t("Status"), render: (r) => r.locked_until && new Date(r.locked_until) > new Date() ? <Badge tone="red">{t("Locked")}</Badge> : r.is_active ? <Badge tone="green">{t("Active")}</Badge> : <Badge tone="red">{t("Disabled")}</Badge> },
          { key: "_a", header: "", align: "right", render: (r) => r.deleted_at ? (can("users.restore") && <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => restore.mutate(r.id)}>{t("Restore")}</Button>) : (
            <span className="flex justify-end gap-0.5">
              {can("users.update") && <Button size="sm" variant="ghost" aria-label={t("Edit")} title={t("Edit")} onClick={() => openForm(r)}><Pencil className="size-4" /></Button>}
              {can("users.permissions") && !r.role?.is_super_admin && r.id !== me?.id && <Link href={`/admin/users/${r.id}/permissions`} aria-label={t("Personal permissions")} title={t("Personal permissions")} className="inline-flex size-8 items-center justify-center rounded-md text-ink-600 hover:bg-ink-100 hover:text-ink-900"><ListChecks className="size-4" /></Link>}
              {can("users.reset_password") && <Button size="sm" variant="ghost" aria-label={t("Reset password")} title={t("Reset password")} onClick={() => { setPw({ password: "", password_confirmation: "" }); setResetting(r); }}><KeyRound className="size-4" /></Button>}
              {can("users.reset_password") && r.locked_until && new Date(r.locked_until) > new Date() && <Button size="sm" variant="ghost" aria-label={t("Unlock")} title={t("Unlock")} onClick={() => unlock.mutate(r.id)}><LockOpen className="size-4" /></Button>}
              {can("users.reset_password") && r.two_factor_confirmed_at && <Button size="sm" variant="ghost" aria-label={t("Reset two-step login")} title={t("Reset two-step login")} onClick={() => confirm(t("Turn off two-step login for {name}? Use this when they lost their phone. They will set it up again at next login.", { name: r.name })) && resetTwoFactor.mutate(r.id)}><ShieldOff className="size-4" /></Button>}
              {can("users.force_logout") && <Button size="sm" variant="ghost" aria-label={t("Log out everywhere")} title={t("Log out everywhere")} onClick={() => forceLogout.mutate(r.id)}><LogOut className="size-4" /></Button>}
              {can("users.delete") && r.id !== me?.id && <Button size="sm" variant="ghost" aria-label={t("Delete")} title={t("Delete")} onClick={() => setDeleting(r)}><Trash2 className="size-4 text-ember-600" /></Button>}
            </span>
          ) },
        ]} empty={{ title: trashed ? t("No deleted users") : t("No staff found") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>

      <Modal open={open} onClose={() => setOpen(false)} title={editing ? t("Edit {name}", { name: editing.name }) : t("Add staff member")} size="lg"
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{editing ? t("Save changes") : t("Add staff member")}</Button></>}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("Full name")} required error={errors.name}>{(p) => <Input {...p} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />}</Field>
          <Field label={t("Username")} required error={errors.username} hint={t("Letters, numbers, - and _ only.")}>{(p) => <Input {...p} autoComplete="off" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} />}</Field>
          <Field label={t("Email")} error={errors.email}>{(p) => <Input {...p} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />}</Field>
          <Field label={t("Phone")} error={errors.phone}>{(p) => <Input {...p} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />}</Field>
          <Field label={t("Role")} required error={errors.role_id}>{(p) => <Select {...p} value={form.role_id} onChange={(e) => setForm({ ...form, role_id: e.target.value })}><option value="">{t("Choose role…")}</option>{roles.data?.data.filter((r) => !r.is_super_admin || me?.role?.is_super_admin).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select>}</Field>
          <Field label={t("Branch")} required={!form.all_branches} error={errors.branch_id}>{(p) => <Select {...p} value={form.branch_id} onChange={(e) => setForm({ ...form, branch_id: e.target.value })}><option value="">{form.all_branches ? t("Default branch (optional)") : t("Choose branch…")}</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</Select>}</Field>
          {!editing && (
            <>
              <Field label={t("Temporary password")} required error={errors.password} hint={t("At least 8 characters with letters and numbers.")}>{(p) => <Input {...p} type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />}</Field>
              <Field label={t("Repeat password")}>{(p) => <Input {...p} type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />}</Field>
            </>
          )}
          <div className="space-y-3 sm:col-span-2">
            <Switch label={t("Can work in all branches")} description={t("Lets the user switch branches and see every branch's data.")} checked={form.all_branches} onChange={(v) => setForm({ ...form, all_branches: v })} />
            <Switch label={t("Must change password at next login")} checked={form.must_change_password} onChange={(v) => setForm({ ...form, must_change_password: v })} />
            <Switch label={t("Active")} description={t("Disabled users are logged out and cannot log in.")} checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
          </div>
        </div>
      </Modal>

      <Modal open={Boolean(resetting)} onClose={() => setResetting(null)} title={t("Reset password for {name}", { name: resetting?.name })} description={t("They are logged out everywhere and must choose a new password at next login.")} size="sm"
        footer={<><Button variant="secondary" onClick={() => setResetting(null)}>{t("Cancel")}</Button><Button loading={reset.isPending} disabled={!pw.password || pw.password !== pw.password_confirmation} onClick={() => reset.mutate(undefined)}>{t("Reset password")}</Button></>}>
        <div className="space-y-4">
          <Field label={t("New temporary password")} error={fieldErrors(reset.error).password}>{(p) => <Input {...p} type="password" autoComplete="new-password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} />}</Field>
          <Field label={t("Repeat password")}>{(p) => <Input {...p} type="password" autoComplete="new-password" value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} />}</Field>
        </div>
      </Modal>
      <ConfirmDialog open={Boolean(deleting)} onClose={() => setDeleting(null)} onConfirm={() => deleting && remove.mutate(deleting.id)} loading={remove.isPending} title={t("Delete {name}?", { name: deleting?.name })} message={t("They are logged out and can no longer log in. Their history stays in the audit logs. You can restore the account later.")} confirmLabel={t("Delete user")} />
    </Guard>
  );
}
