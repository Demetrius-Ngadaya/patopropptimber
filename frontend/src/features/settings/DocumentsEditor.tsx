"use client";

import { Check } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Checkbox, Field, Input, Textarea } from "@/components/ui";

export const COMPANY_FIELD_LABELS: Record<string, string> = {
  logo: "Logo",
  address: "Address & P.O. Box",
  phone: "Phone",
  whatsapp: "WhatsApp",
  email: "Email",
  website: "Website",
  tin: "TIN",
  vrn: "VRN",
  bank: "Bank / mobile money details",
};
const DOCUMENT_LABELS: Record<string, string> = {
  receipt: "Receipts",
  invoice: "Invoices",
  quotation: "Quotations",
  proforma: "Proformas",
  statement: "Statements",
  report: "Reports",
  email: "Emails",
};
const COLUMN_LABELS: Record<string, string> = { sku: "Product code (SKU)", unit: "Unit", discount: "Discount", tax: "VAT per line" };
const BLOCK_LABELS: Record<string, string> = {
  customer_tin: "Customer TIN",
  customer_vrn: "Customer VRN",
  customer_address: "Customer address",
  bank_details: "How to pay (bank details)",
  terms: "Terms",
  notes: "Notes",
  signature: "Signature lines",
  served_by: "Prepared by (staff name)",
  amount_in_words: "Amount in words",
};
const TYPES = [
  { key: "quotation", label: "Quotations", days: "quotation_valid_days", daysLabel: "Valid for (days)" },
  { key: "proforma", label: "Proforma invoices", days: "proforma_valid_days", daysLabel: "Valid for (days)" },
  { key: "invoice", label: "Invoices", days: "invoice_due_days", daysLabel: "Payment due after (days)" },
] as const;

/** Settings → Documents: company details per document, then each document type's layout. */
export function DocumentsEditor({ values, set, errors, disabled }: {
  values: Record<string, unknown>;
  set: (key: string, value: unknown) => void;
  errors: Record<string, string>;
  disabled: boolean;
}) {
  const t = useT();
  const matrix = (values.document_company_fields ?? {}) as Record<string, string[]>;
  const toggleCell = (doc: string, field: string) => {
    const list = matrix[doc] ?? [];
    set("document_company_fields", { ...matrix, [doc]: list.includes(field) ? list.filter((f) => f !== field) : [...list, field] });
  };
  const list = (key: string) => (Array.isArray(values[key]) ? (values[key] as string[]) : []);
  const toggle = (key: string, item: string) => set(key, list(key).includes(item) ? list(key).filter((x) => x !== item) : [...list(key), item]);
  const text = (key: string) => (values[key] === null || values[key] === undefined ? "" : String(values[key]));

  return (
    <div className="space-y-8">
      <section>
        <h3 className="text-[15px] font-semibold text-ink-900">{t("Company details on each document")}</h3>
        <p className="mb-3 mt-0.5 text-sm text-ink-500">{t("Tick what each document shows. Fill the details themselves (TIN, VRN, WhatsApp, website...) on the Business tab.")}</p>
        <div className="overflow-x-auto rounded-lg ring-1 ring-ink-200">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-ink-50">
              <tr>
                <th className="px-3 py-2 text-left text-[12.5px] font-semibold text-ink-600">{t("Detail")}</th>
                {Object.keys(DOCUMENT_LABELS).map((d) => <th key={d} className="px-2 py-2 text-center text-[12.5px] font-semibold text-ink-600">{t(DOCUMENT_LABELS[d])}</th>)}
              </tr>
            </thead>
            <tbody>
              {Object.keys(COMPANY_FIELD_LABELS).map((f) => (
                <tr key={f} className="border-t border-ink-100">
                  <td className="px-3 py-1.5 text-ink-800">{t(COMPANY_FIELD_LABELS[f])}</td>
                  {Object.keys(DOCUMENT_LABELS).map((d) => {
                    const on = (matrix[d] ?? []).includes(f);
                    return (
                      <td key={d} className="px-2 py-1.5 text-center">
                        <button type="button" role="checkbox" aria-checked={on} disabled={disabled} onClick={() => toggleCell(d, f)}
                          aria-label={`${t(COMPANY_FIELD_LABELS[f])} – ${t(DOCUMENT_LABELS[d])}`}
                          className={cn("inline-grid size-6 place-items-center rounded-md ring-1 transition-colors disabled:opacity-60", on ? "bg-brand-600 text-white ring-brand-600" : "bg-surface ring-ink-300 hover:ring-brand-500")}>
                          {on && <Check className="size-4" />}
                        </button>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {errors.document_company_fields && <p className="mt-1 text-xs text-ember-600">{errors.document_company_fields}</p>}
      </section>

      <Field label={t("Bank and mobile money details for payment")} hint={t("Printed under “How to pay” on invoices and proformas. One line per account.")} error={errors.business_bank_details}>
        {(p) => <Textarea {...p} rows={3} disabled={disabled} value={text("business_bank_details")} onChange={(e) => set("business_bank_details", e.target.value)} placeholder={"CRDB Bank · 0150 1234 5678 · Duka Lako Ltd\nM-Pesa Lipa Namba 123456"} />}
      </Field>

      {TYPES.map((type) => (
        <section key={type.key} className="rounded-lg p-4 ring-1 ring-ink-200">
          <h3 className="mb-3 text-[15px] font-semibold text-ink-900">{t(type.label)}</h3>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label={t("Title on the document")} error={errors[`${type.key}_title`]}>
              {(p) => <Input {...p} disabled={disabled} value={text(`${type.key}_title`)} onChange={(e) => set(`${type.key}_title`, e.target.value)} />}
            </Field>
            <Field label={t(type.daysLabel)} error={errors[type.days]}>
              {(p) => <Input {...p} type="number" min={0} disabled={disabled} value={text(type.days)} onChange={(e) => set(type.days, e.target.value === "" ? "" : Number(e.target.value))} />}
            </Field>
            <Field label={t("Footer")} error={errors[`${type.key}_footer`]}>
              {(p) => <Input {...p} disabled={disabled} value={text(`${type.key}_footer`)} onChange={(e) => set(`${type.key}_footer`, e.target.value)} />}
            </Field>
            <Field label={t("Terms (can be changed on each document)")} error={errors[`${type.key}_terms`]} className="sm:col-span-3">
              {(p) => <Textarea {...p} rows={2} disabled={disabled} value={text(`${type.key}_terms`)} onChange={(e) => set(`${type.key}_terms`, e.target.value)} />}
            </Field>
          </div>
          <div className="mt-4 grid gap-4 sm:grid-cols-[1fr_2fr]">
            <fieldset>
              <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Extra columns")}</legend>
              <div className="flex flex-col gap-1.5">
                {Object.keys(COLUMN_LABELS).map((c) => <Checkbox key={c} label={t(COLUMN_LABELS[c])} disabled={disabled} checked={list(`${type.key}_columns`).includes(c)} onChange={() => toggle(`${type.key}_columns`, c)} />)}
              </div>
            </fieldset>
            <fieldset>
              <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Sections")}</legend>
              <div className="grid gap-1.5 sm:grid-cols-2">
                {Object.keys(BLOCK_LABELS).map((b) => <Checkbox key={b} label={t(BLOCK_LABELS[b])} disabled={disabled} checked={list(`${type.key}_blocks`).includes(b)} onChange={() => toggle(`${type.key}_blocks`, b)} />)}
              </div>
            </fieldset>
          </div>
        </section>
      ))}
    </div>
  );
}

/** Keys drawn by DocumentsEditor instead of the generic field list. */
export const DOCUMENT_SETTING_KEYS = new Set([
  "document_company_fields", "business_bank_details",
  ...TYPES.flatMap((x) => [`${x.key}_title`, x.days, `${x.key}_terms`, `${x.key}_footer`, `${x.key}_columns`, `${x.key}_blocks`]),
]);
