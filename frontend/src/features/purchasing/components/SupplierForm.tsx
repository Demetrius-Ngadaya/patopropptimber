"use client";

import { useState } from "react";
import { api, type ApiResponse } from "@/lib/api";
import { fieldErrors, useAction } from "@/lib/hooks";
import { Button, Field, Input, Modal, Switch, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

export type Supplier = { id: number; code: string; name: string; contact_person: string | null; phone: string | null; email: string | null; address: string | null; tin: string | null; balance: string; payment_terms_days: number; is_active: boolean; notes: string | null; deleted_at?: string | null };

const empty = { name: "", contact_person: "", phone: "", email: "", address: "", tin: "", payment_terms_days: 30, is_active: true, notes: "" };

export function SupplierForm({ open, onClose, supplier }: { open: boolean; onClose: () => void; supplier?: Supplier | null }) {
  const t = useT();
  const [form, setForm] = useState(() => (supplier ? { ...empty, ...Object.fromEntries(Object.entries(supplier).map(([k, v]) => [k, v ?? ""])) } as typeof empty : empty));
  const save = useAction(
    () => (supplier ? api.put<ApiResponse<Supplier>>(`admin/suppliers/${supplier.id}`, form) : api.post<ApiResponse<Supplier>>("admin/suppliers", form)),
    { invalidate: [["admin/suppliers"], [`admin/suppliers/${supplier?.id}`]], onSuccess: onClose },
  );
  const errors = fieldErrors(save.error);
  return (
    <Modal open={open} onClose={onClose} title={supplier ? t("Edit {name}", { name: supplier.name }) : t("New supplier")} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{supplier ? t("Save changes") : t("Add supplier")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Name")} required error={errors.name} className="sm:col-span-2">{(p) => <Input {...p} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />}</Field>
        <Field label={t("Contact person")}>{(p) => <Input {...p} value={form.contact_person} onChange={(e) => setForm({ ...form, contact_person: e.target.value })} />}</Field>
        <Field label={t("Phone")}>{(p) => <Input {...p} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />}</Field>
        <Field label={t("Email")} error={errors.email}>{(p) => <Input {...p} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />}</Field>
        <Field label={t("TIN")}>{(p) => <Input {...p} value={form.tin} onChange={(e) => setForm({ ...form, tin: e.target.value })} />}</Field>
        <Field label={t("Address")} className="sm:col-span-2">{(p) => <Input {...p} value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />}</Field>
        <Field label={t("Payment terms (days)")} hint={t("Used as the default due date for credit purchases.")}>{(p) => <Input {...p} type="number" min={0} value={form.payment_terms_days} onChange={(e) => setForm({ ...form, payment_terms_days: Number(e.target.value) })} />}</Field>
        <div className="self-end"><Switch label={t("Active")} checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} /></div>
        <Field label={t("Notes")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />}</Field>
      </div>
    </Modal>
  );
}

