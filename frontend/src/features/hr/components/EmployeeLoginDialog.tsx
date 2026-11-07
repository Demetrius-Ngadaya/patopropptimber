"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { fieldErrors, useAction } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Button, Field, Input, Modal, Switch } from "@/components/ui";
import type { Employee } from "../types";

/** Create the employee's login (if it was not made at hiring) or change username, password and access. */
export function EmployeeLoginDialog({ employee, open, onClose }: { employee: Employee; open: boolean; onClose: () => void }) {
  const t = useT();
  const has = Boolean(employee.user);
  const [form, setForm] = useState({ username: "", password: "", is_active: true });
  useEffect(() => {
    if (open) setForm({ username: employee.user?.username ?? "", password: "", is_active: employee.user?.is_active ?? true });
  }, [open, employee.user]);

  const save = useAction(() => api.put(`admin/hr/employees/${employee.id}/login`, {
    username: form.username.trim(),
    password: form.password || null,
    ...(has ? { is_active: form.is_active } : {}),
  }), { invalidate: [[`admin/hr/employees/${employee.id}`]], onSuccess: onClose });
  const e = fieldErrors(save.error);

  return (
    <Modal open={open} onClose={onClose} title={has ? t("Change login of {name}", { name: employee.full_name }) : t("Create a login for {name}", { name: employee.full_name })}
      description={has ? t("Leave the password empty to keep the current one. A new password must be changed at next login.") : t("The employee can then see payslips, apply for leave and clock in. They must change this password at first login.")}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={form.username.trim().length < 3 || (!has && form.password.length < 8)} onClick={() => save.mutate(undefined)}>{has ? t("Save changes") : t("Create login")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Username")} required error={e.username} hint={t("Letters, numbers, - and _ only.")}>{(p) => <Input {...p} autoComplete="off" value={form.username} onChange={(ev) => setForm({ ...form, username: ev.target.value })} />}</Field>
        <Field label={has ? t("New password (optional)") : t("Temporary password")} required={!has} error={e.password} hint={t("At least 8 characters with letters and numbers.")}>{(p) => <Input {...p} type="password" autoComplete="new-password" value={form.password} onChange={(ev) => setForm({ ...form, password: ev.target.value })} />}</Field>
        {has && <div className="sm:col-span-2"><Switch label={t("Login enabled")} description={t("Turning this off logs the employee out at once.")} checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} /></div>}
      </div>
    </Modal>
  );
}
