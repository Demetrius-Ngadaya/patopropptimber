"use client";

import { useEffect, useState } from "react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors, useAction } from "@/lib/hooks";
import type { Customer } from "@/lib/types";
import { Button, Field, Input, Modal, Select, Switch, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

const empty = { name: "", phone: "", alt_phone: "", email: "", address: "", city: "", tin: "", customer_type: "retail", credit_limit: 0, allow_credit: false, sms_opt_in: true, is_active: true, notes: "" };

export function CustomerForm({ open, onClose, customer, onSaved }: { open: boolean; onClose: () => void; customer?: Customer | null; onSaved?: (c: Customer) => void }) {
  const t = useT();
  const { can } = useAuth();
  const [form, setForm] = useState(empty);
  useEffect(() => {
    if (open) setForm(customer ? { ...empty, ...Object.fromEntries(Object.entries(customer).filter(([k]) => k in empty).map(([k, v]) => [k, v ?? (empty as Record<string, unknown>)[k]])) } as typeof empty : empty);
  }, [open, customer]);

  const save = useAction(
    (body: typeof empty) => (customer ? api.put<ApiResponse<Customer>>(`admin/customers/${customer.id}`, body) : api.post<ApiResponse<Customer>>("admin/customers", body)),
    {
      invalidate: [["admin/customers"], [`admin/customers/${customer?.id}`]],
      onSuccess: (r) => {
        onSaved?.(r.data);
        onClose();
      },
    },
  );
  const errors = fieldErrors(save.error);
  const set = <K extends keyof typeof empty>(k: K, v: (typeof empty)[K]) => setForm((f) => ({ ...f, [k]: v }));
  const creditAllowed = can("customers.set_credit");

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={customer ? t("Edit {name}", { name: customer.name }) : t("New customer")}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button>
          <Button type="submit" form="customer-form" loading={save.isPending}>{customer ? t("Save changes") : t("Add customer")}</Button>
        </>
      }
    >
      <form id="customer-form" className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save.mutate({ ...form, credit_limit: Number(form.credit_limit) || 0 }); }}>
        <Field label={t("Full name / business name")} required error={errors.name} className="sm:col-span-2">
          {(p) => <Input {...p} value={form.name} onChange={(e) => set("name", e.target.value)} />}
        </Field>
        <Field label={t("Phone")} error={errors.phone} hint={t("Used for receipts and debt reminders.")}>
          {(p) => <Input {...p} inputMode="tel" placeholder="0712 345 678" value={form.phone} onChange={(e) => set("phone", e.target.value)} />}
        </Field>
        <Field label={t("Other phone")} error={errors.alt_phone}>
          {(p) => <Input {...p} inputMode="tel" value={form.alt_phone} onChange={(e) => set("alt_phone", e.target.value)} />}
        </Field>
        <Field label={t("Email")} error={errors.email}>
          {(p) => <Input {...p} type="email" value={form.email} onChange={(e) => set("email", e.target.value)} />}
        </Field>
        <Field label={t("Type")}>
          {(p) => (
            <Select {...p} value={form.customer_type} onChange={(e) => set("customer_type", e.target.value)}>
              <option value="retail">{t("Retail")}</option>
              <option value="wholesale">{t("Wholesale")}</option>
              <option value="institution">{t("Institution")}</option>
            </Select>
          )}
        </Field>
        <Field label={t("Address")} error={errors.address}>
          {(p) => <Input {...p} value={form.address} onChange={(e) => set("address", e.target.value)} />}
        </Field>
        <Field label={t("City / area")}>
          {(p) => <Input {...p} value={form.city} onChange={(e) => set("city", e.target.value)} />}
        </Field>
        <Field label={t("TIN")}>
          {(p) => <Input {...p} value={form.tin} onChange={(e) => set("tin", e.target.value)} />}
        </Field>
        <Field label={t("Credit limit")} error={errors.credit_limit} hint={creditAllowed ? t("0 means no limit.") : t("Only staff allowed to set credit can change this.")}>
          {(p) => <Input {...p} type="number" min={0} disabled={!creditAllowed} value={form.credit_limit} onChange={(e) => set("credit_limit", Number(e.target.value))} />}
        </Field>
        <div className="space-y-3 sm:col-span-2">
          <Switch label={t("Can buy on credit")} description={t("Allows sales where the customer pays later.")} checked={form.allow_credit} disabled={!creditAllowed} onChange={(v) => set("allow_credit", v)} />
          <Switch label={t("Receive SMS messages")} checked={form.sms_opt_in} onChange={(v) => set("sms_opt_in", v)} />
          <Switch label={t("Active")} checked={form.is_active} onChange={(v) => set("is_active", v)} />
        </div>
        <Field label={t("Notes")} className="sm:col-span-2">
          {(p) => <Textarea {...p} rows={2} value={form.notes} onChange={(e) => set("notes", e.target.value)} />}
        </Field>
      </form>
    </Modal>
  );
}
