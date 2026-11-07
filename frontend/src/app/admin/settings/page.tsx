"use client";

import Link from "next/link";

import { useEffect, useRef, useState } from "react";
import { ImageUp, Plus, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { humanize } from "@/lib/format";
import { Guard } from "@/components/layout/Guard";
import { Button, ErrorState, Field, Input, PageHeader, Panel, Select, Spinner, Switch, Tabs } from "@/components/ui";
import { isLocale, LOCALE_NAMES, useT } from "@/lib/i18n";
import { AppearanceEditor } from "@/features/settings/AppearanceEditor";
import { MessagingEditor, type MessagingMeta } from "@/features/settings/MessagingEditor";
import { LabelsEditor } from "@/features/settings/LabelsEditor";
import { DocumentsEditor, DOCUMENT_SETTING_KEYS } from "@/features/settings/DocumentsEditor";
import { DeliveryEditor, DELIVERY_SETTING_KEYS } from "@/features/settings/DeliveryEditor";
import { FeaturesEditor } from "@/features/settings/FeaturesEditor";
import { OnlinePaymentsStatusPanel } from "@/features/payments/OnlinePaymentsStatus";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import type { Channel } from "@/features/messaging/channels";

/** Settings drawn by their own editor rather than the generic field list. */
const CUSTOM = new Set(["messaging_channels", "notification_channels", "notify_sms_numbers", "notify_whatsapp_numbers", "notify_emails", "qr_mode", "qr_public_fields", ...DOCUMENT_SETTING_KEYS, ...DELIVERY_SETTING_KEYS, "optional_modules", "purchase_reminder_channels"]);

type Setting = { key: string; label: string; type: "string" | "int" | "float" | "bool" | "json"; value: unknown; options: string[] | null };
type Payload = { groups: Record<string, Setting[]>; logo_url: string | null; messaging: MessagingMeta; qr_fields: string[] };

const groupLabels: Record<string, string> = {
  business: "Business",
  sales: "Sales & receipts",
  shop: "Online shop",
  payments: "Online payments",
  security: "Security & sessions",
  notifications: "Messages",
  documents: "Invoices & quotations",
  delivery: "Delivery",
  customers: "Customer insights",
  features: "Optional features",
  agency: "Uwakala",
  shifts: "Shifts",
  purchasing: "Purchases",
  labels: "Barcodes & QR codes",
  accounting: "Accounting",
  payroll: "Payroll & HR",
  ai: "AI",
  appearance: "Appearance",
};
const groupHelp: Record<string, string> = {
  business: "Shown on receipts, reports and the online shop.",
  sales: "How the till behaves and what receipts look like.",
  shop: "What customers see and can do online.",
  payments: "Customers pay from M-Pesa, Airtel Money, Mixx by Yas or HaloPesa: they get a prompt on their phone and enter their PIN. The money arrives in your Snippe account. Switch it on for the till, the online shop, or both.",
  security: "Session timeout is in minutes of inactivity. Changes apply from each person's next request.",
  notifications: "SMS, WhatsApp and email: which are used, who receives notifications and reports, and how each kind of message is sent.",
  labels: "Barcodes and QR codes on products and labels.",
  documents: "What quotations, proforma invoices and invoices look like, and which company details (TIN, VRN, contacts...) appear on receipts, documents and reports.",
  delivery: "Delivery fees are worked out from the distance between the shop and the customer's pin on the map.",
  customers: "How customers are grouped on the Customer insights page and for promotions.",
  features: "Switch on the parts of the system your business needs.",
  agency: "How the uwakala days are counted. Switch Uwakala on under Optional features.",
  shifts: "How shifts work. Switch Shifts on under Optional features.",
  purchasing: "Reminders to pay suppliers for goods bought on credit. Each purchase can also have its own reminder day and ways.",
  accounting: "VAT, the financial year and how transactions are posted.",
  payroll: "Statutory rates used by payroll. Check them against the current TRA, NSSF and WCF rates before each financial year: they change.",
  appearance: "The colours of the whole system for every user: menu, buttons and highlights. Leave a colour empty to use the built-in one.",
  ai: "Forecasts, anomaly alerts and credit scores run inside the system. The assistant and written summaries use the language model chosen with AI_PROVIDER on the server (Groq, Gemini, OpenRouter and Ollama are free).",
};

type Band = { from: number; to: number | null; rate: number; base: number };

/** Editor for progressive tax bands: tax = base + rate% of the amount above "from". */
function BandsEditor({ value, onChange, disabled, error }: { value: Band[]; onChange: (v: Band[]) => void; disabled: boolean; error?: string }) {
  const t = useT();
  const rows = Array.isArray(value) ? value : [];
  const set = (i: number, k: keyof Band, v: string) => onChange(rows.map((r, j) => (j === i ? { ...r, [k]: v === "" ? (k === "to" ? null : 0) : Number(v) } : r)));
  return (
    <div className="sm:col-span-2">
      <p className="mb-1 text-sm font-medium text-ink-700">{t("Monthly PAYE bands")}</p>
      <p className="mb-2 text-xs text-ink-500">{t("Tax = fixed amount + rate % of the income above “From”. Leave “To” empty on the last band.")}</p>
      <div className="overflow-x-auto rounded-lg ring-1 ring-ink-200">
        <table className="w-full text-sm">
          <thead className="bg-ink-50 text-left text-xs uppercase text-ink-500"><tr><th className="px-2 py-1.5">{t("From")}</th><th className="px-2 py-1.5">{t("To")}</th><th className="px-2 py-1.5">{t("Rate %")}</th><th className="px-2 py-1.5">{t("Fixed amount")}</th><th /></tr></thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={i} className="border-t border-ink-100">
                {(["from", "to", "rate", "base"] as const).map((k) => (
                  <td key={k} className="px-1 py-1"><Input type="number" min={0} step="any" disabled={disabled} value={r[k] === null ? "" : String(r[k])} onChange={(e) => set(i, k, e.target.value)} className="h-8" aria-label={k} /></td>
                ))}
                <td className="px-1">{!disabled && <Button size="sm" variant="ghost" aria-label={t("Remove")} onClick={() => onChange(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {error && <p className="mt-1 text-xs text-ember-600">{error}</p>}
      {!disabled && <Button size="sm" variant="secondary" className="mt-2" icon={<Plus className="size-4" />} onClick={() => { const last = rows[rows.length - 1]; onChange([...rows, { from: last?.to ?? 0, to: null, rate: 0, base: 0 }]); }}>{t("Add band")}</Button>}
    </div>
  );
}

export default function SettingsPage() {
  const t = useT();
  const { can, refresh } = useAuth();
  const q = useApi<ApiResponse<Payload>>("admin/settings");
  const [tab, setTab] = useState("business");
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [dirty, setDirty] = useState<Set<string>>(new Set());
  const file = useRef<HTMLInputElement>(null);
  const editable = can("settings.update");

  // Fresh server data (after saving, or after a logo upload) must not wipe what the
  // admin has typed but not saved yet: unsaved fields keep their typed value.
  const dirtyRef = useRef<Set<string>>(new Set());
  useEffect(() => {
    if (!q.data) return;
    const server: Record<string, unknown> = {};
    Object.values(q.data.data.groups).flat().forEach((s) => (server[s.key] = s.value));
    setValues((current) => {
      const next = { ...server };
      dirtyRef.current.forEach((k) => { if (k in current) next[k] = current[k]; });
      return next;
    });
  }, [q.data]);

  const save = useAction(() => {
    const body: Record<string, unknown> = {};
    dirty.forEach((k) => (body[k] = values[k]));
    return api.put("admin/settings", body);
  }, { invalidate: [["admin/settings"], ["public/settings"]], onSuccess: () => { dirtyRef.current = new Set(); setDirty(new Set()); refresh(); } });
  const upload = useAction((f: File) => {
    const form = new FormData();
    form.append("logo", f);
    return api.post("admin/settings/logo", form);
  }, { invalidate: [["admin/settings"], ["public/settings"]] });
  const errors = fieldErrors(save.error);

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error) return <ErrorState message={q.error.message} onRetry={() => q.refetch()} />;
  const groups = q.data!.data.groups;
  const set = (key: string, v: unknown) => { dirtyRef.current = new Set(dirtyRef.current).add(key); setValues((s) => ({ ...s, [key]: v })); setDirty(dirtyRef.current); };

  const renderField = (s: Setting) => {
    if (s.key === "business_logo" || CUSTOM.has(s.key)) return null;
    const v = values[s.key];
    if (s.type === "bool") return <Switch key={s.key} label={t(s.label)} checked={Boolean(v)} disabled={!editable} onChange={(c) => set(s.key, c)} />;
    if (s.type === "json") {
      const nested = Object.entries(errors).find(([k]) => k.startsWith(`${s.key}.`))?.[1];
      return <BandsEditor key={s.key} value={v as Band[]} disabled={!editable} onChange={(b) => set(s.key, b)} error={errors[s.key] ?? nested} />;
    }
    return (
      <Field key={s.key} label={t(s.label)} error={errors[s.key]}>
        {(p) => s.options ? (
          <Select {...p} disabled={!editable} value={String(v ?? "")} onChange={(e) => set(s.key, e.target.value)}>{s.options!.map((o) => <option key={o} value={o}>{isLocale(o) ? LOCALE_NAMES[o].native : t(humanize(o))}</option>)}</Select>
        ) : (
          <Input {...p} disabled={!editable} type={s.type === "string" ? "text" : "number"} step={s.type === "float" ? "any" : "1"} inputMode={s.type === "string" ? undefined : "decimal"}
            value={v === null || v === undefined ? "" : String(v)}
            onChange={(e) => set(s.key, s.type === "string" ? e.target.value : e.target.value === "" ? "" : Number(e.target.value))} />
        )}
      </Field>
    );
  };

  const current = groups[tab] ?? [];
  return (
    <Guard perm="settings.view">
      <PageHeader title={t("Settings")} description={t("Business details and how the system behaves.")} actions={editable && <Button loading={save.isPending} disabled={dirty.size === 0} onClick={() => save.mutate(undefined)}>{dirty.size ? t("Save changes ({count})", { count: dirty.size }) : t("Saved")}</Button>} />
      <Tabs value={tab} onChange={setTab} tabs={Object.keys(groups).map((g) => ({ value: g, label: t(groupLabels[g] ?? humanize(g)) }))} />
      <div className="mt-5 grid gap-5 lg:grid-cols-[1fr_280px]">
        <Panel>
          <p className="mb-5 text-sm text-ink-600">{groupHelp[tab] ? t(groupHelp[tab]) : null}</p>
          {tab === "appearance" ? (
            <AppearanceEditor
              value={{ primary: values.theme_primary as string | null, accent: values.theme_accent as string | null, sidebar: values.theme_sidebar as string | null }}
              disabled={!editable}
              errors={{ primary: errors.theme_primary, accent: errors.theme_accent, sidebar: errors.theme_sidebar }}
              onChange={(key, v) => set(`theme_${key}`, v)}
            />
          ) : tab === "features" ? (
            <FeaturesEditor value={(values.optional_modules ?? []) as string[]} onChange={(v) => set("optional_modules", v)} disabled={!editable} />
          ) : tab === "documents" ? (
            <DocumentsEditor values={values} set={set} errors={errors} disabled={!editable} />
          ) : (
            <>
              {tab === "purchasing" && (
                <div className="mb-6">
                  <ChannelPicker value={(values.purchase_reminder_channels ?? []) as Channel[]} onChange={(v) => set("purchase_reminder_channels", v)} label={t("Usual way of reminding")} disabled={!editable} />
                  <p className="mt-1 text-xs text-ink-500">{t("Reminders go to the notification numbers and emails in Settings → Messages, and to the bell of people who pay suppliers.")}</p>
                </div>
              )}
              {tab === "delivery" && <DeliveryEditor values={values} set={set} errors={errors} disabled={!editable} currency={String(values.currency ?? "")} />}
              {tab === "notifications" && can("settings.notifications") && (
                <p className="mb-5 rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-800">
                  {t("Scheduled reports and alerts (sales summary, low stock, debts...) with their own recipients and wording are set up in")}{" "}
                  <Link href="/admin/notification-rules" className="font-semibold underline underline-offset-2">{t("Automatic notifications")}</Link>.
                </p>
              )}
              {tab === "notifications" && (
                <div className="mb-7 border-b border-ink-100 pb-7">
                  <MessagingEditor
                    meta={q.data!.data.messaging}
                    values={{
                      messaging_channels: (values.messaging_channels ?? []) as never,
                      notification_channels: (values.notification_channels ?? {}) as never,
                      notify_sms_numbers: String(values.notify_sms_numbers ?? ""),
                      notify_whatsapp_numbers: String(values.notify_whatsapp_numbers ?? ""),
                      notify_emails: String(values.notify_emails ?? ""),
                    }}
                    onChange={(k, v) => set(k, v)}
                    errors={errors}
                    disabled={!editable}
                  />
                </div>
              )}
              <div className="grid gap-4 sm:grid-cols-2">
                {current.filter((s) => s.type !== "bool").map(renderField)}
              </div>
            </>
          )}
          {current.some((s) => s.type === "bool") && <div className="mt-5 space-y-3 border-t border-ink-100 pt-5">{current.filter((s) => s.type === "bool").map(renderField)}</div>}
          {tab === "labels" && (
            <div className="mt-6 border-t border-ink-100 pt-6">
              <LabelsEditor
                mode={values.qr_mode === "text" ? "text" : "link"}
                fields={(values.qr_public_fields ?? []) as string[]}
                allFields={q.data!.data.qr_fields}
                onChange={(k, v) => set(k, v)}
                disabled={!editable}
                qrEnabled={Boolean(values.qr_enabled)}
              />
            </div>
          )}
        </Panel>
        {tab === "payments" && <OnlinePaymentsStatusPanel />}
        {tab === "business" && (
          <Panel title={t("Logo")}>
            <div className="flex aspect-[3/2] items-center justify-center rounded-md bg-ink-50 ring-1 ring-ink-100">
              {/* eslint-disable-next-line @next/next/no-img-element -- logo is served by the API storage, not a Next image domain */}
              {q.data!.data.logo_url ? <img src={q.data!.data.logo_url} alt={t("Business logo")} className="max-h-full max-w-full object-contain p-3" /> : <span className="text-sm text-ink-500">{t("No logo yet")}</span>}
            </div>
            <p className="mt-2 text-xs text-ink-500">{t("PNG, JPG or WebP, up to 1 MB.")}</p>
            {editable && (
              <>
                <input ref={file} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = ""; }} />
                <Button variant="secondary" className="mt-3 w-full" loading={upload.isPending} icon={<ImageUp className="size-4" />} onClick={() => file.current?.click()}>{t("Upload logo")}</Button>
              </>
            )}
          </Panel>
        )}
      </div>
    </Guard>
  );
}
