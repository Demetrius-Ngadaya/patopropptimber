"use client";

import { CircleAlert, CircleCheck } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Field, Textarea } from "@/components/ui";
import { CHANNELS, type Channel } from "@/features/messaging/channels";

export type MessagingMeta = {
  channels: Channel[];
  configured: Record<Channel, boolean>;
  types: { key: string; label: string; audience: "staff" | "customer" }[];
};

type Values = {
  messaging_channels: Channel[];
  notification_channels: Record<string, Channel[]>;
  notify_sms_numbers: string;
  notify_whatsapp_numbers: string;
  notify_emails: string;
};

const SETUP: Record<Channel, string> = {
  sms: "Set SMS_ENABLED, SMS_API_TOKEN and SMS_SENDER_ID in the server .env file.",
  whatsapp: "Set WHATSAPP_ENABLED, WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID and WHATSAPP_TEMPLATE in the server .env file.",
  email: "Set MAIL_MAILER, MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS in the server .env file.",
};

/**
 * Settings → Messages: which ways of sending exist, who receives staff
 * notifications, and how each kind of message is sent.
 */
export function MessagingEditor({ meta, values, onChange, errors, disabled }: {
  meta: MessagingMeta;
  values: Values;
  onChange: (key: keyof Values, value: unknown) => void;
  errors: Record<string, string>;
  disabled?: boolean;
}) {
  const t = useT();
  const enabled = values.messaging_channels ?? [];
  const routing = values.notification_channels ?? {};

  const toggleEnabled = (c: Channel) => onChange("messaging_channels", enabled.includes(c) ? enabled.filter((x) => x !== c) : [...enabled, c]);
  const toggleRoute = (type: string, c: Channel) => {
    const now = routing[type] ?? [];
    onChange("notification_channels", { ...routing, [type]: now.includes(c) ? now.filter((x) => x !== c) : [...now, c] });
  };

  const matrix = (audience: "staff" | "customer") => (
    <div className="overflow-x-auto rounded-md ring-1 ring-ink-100">
      <table className="w-full min-w-[520px] text-sm">
        <thead className="bg-ink-50 text-left text-xs text-ink-600">
          <tr>
            <th className="px-3 py-2 font-medium">{audience === "staff" ? t("Notification") : t("Message to the customer")}</th>
            {audience === "staff" && <th className="px-2 py-2 text-center font-medium">{t("Bell")}</th>}
            {CHANNELS.map((c) => <th key={c.value} className={cn("px-2 py-2 text-center font-medium", !enabled.includes(c.value) && "opacity-40")}>{t(c.label)}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-ink-100">
          {meta.types.filter((ty) => ty.audience === audience).map((ty) => (
            <tr key={ty.key}>
              <td className="px-3 py-2 text-ink-800">{t(ty.label)}</td>
              {audience === "staff" && <td className="px-2 py-2 text-center"><input type="checkbox" className="size-4 accent-brand-600" checked disabled aria-label={t("Always in the bell")} /></td>}
              {CHANNELS.map((c) => (
                <td key={c.value} className="px-2 py-2 text-center">
                  <input type="checkbox" className="size-4 accent-brand-600 disabled:opacity-30" disabled={disabled || !enabled.includes(c.value)}
                    aria-label={`${t(ty.label)}: ${t(c.label)}`}
                    checked={enabled.includes(c.value) && (routing[ty.key] ?? []).includes(c.value)}
                    onChange={() => toggleRoute(ty.key, c.value)} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );

  return (
    <div className="space-y-7">
      <section>
        <h3 className="text-sm font-semibold text-ink-900">{t("Ways of sending")}</h3>
        <p className="mb-3 text-sm text-ink-600">{t("Only the ways you tick can be chosen by staff and used for notifications and reports.")}</p>
        <div className="grid gap-2 sm:grid-cols-3">
          {CHANNELS.map((c) => {
            const on = enabled.includes(c.value);
            const ready = meta.configured[c.value];
            return (
              <label key={c.value} className={cn("flex cursor-pointer items-start gap-3 rounded-md p-3 ring-1", on ? "bg-brand-50 ring-brand-500" : "ring-ink-200", disabled && "cursor-default")}>
                <input type="checkbox" className="mt-0.5 size-4 accent-brand-600" checked={on} disabled={disabled} onChange={() => toggleEnabled(c.value)} />
                <span className="min-w-0">
                  <span className="flex items-center gap-1.5 text-sm font-medium text-ink-900"><c.icon className="size-4" />{t(c.label)}</span>
                  <span className={cn("mt-1 flex items-start gap-1 text-xs", ready ? "text-brand-700" : "text-saffron-700")}>
                    {ready ? <CircleCheck className="mt-px size-3.5 shrink-0" /> : <CircleAlert className="mt-px size-3.5 shrink-0" />}
                    {ready ? t("Set up on the server") : t(SETUP[c.value])}
                  </span>
                </span>
              </label>
            );
          })}
        </div>
        {errors.messaging_channels && <p className="mt-1 text-xs text-ember-600">{errors.messaging_channels}</p>}
      </section>

      <section>
        <h3 className="text-sm font-semibold text-ink-900">{t("Who receives notifications and reports")}</h3>
        <p className="mb-3 text-sm text-ink-600">{t("Owners and managers who should get stock alerts, reports and other notifications outside the system.")}</p>
        <div className="grid gap-4 md:grid-cols-3">
          <Field label={t("SMS numbers")} error={errors.notify_sms_numbers} hint={t("Separate with commas, e.g. 0712 345 678, 0754 000 111")}>
            {(p) => <Textarea {...p} rows={3} disabled={disabled} value={values.notify_sms_numbers ?? ""} onChange={(e) => onChange("notify_sms_numbers", e.target.value)} />}
          </Field>
          <Field label={t("WhatsApp numbers")} error={errors.notify_whatsapp_numbers} hint={t("Numbers with WhatsApp, separated with commas")}>
            {(p) => <Textarea {...p} rows={3} disabled={disabled} value={values.notify_whatsapp_numbers ?? ""} onChange={(e) => onChange("notify_whatsapp_numbers", e.target.value)} />}
          </Field>
          <Field label={t("Email addresses")} error={errors.notify_emails} hint={t("Separate with commas")}>
            {(p) => <Textarea {...p} rows={3} disabled={disabled} value={values.notify_emails ?? ""} onChange={(e) => onChange("notify_emails", e.target.value)} />}
          </Field>
        </div>
      </section>

      <section>
        <h3 className="text-sm font-semibold text-ink-900">{t("How each notification is sent")}</h3>
        <p className="mb-3 text-sm text-ink-600">{t("Every notification appears in the bell for the staff whose role allows it. Tick where else it should go. SMS is a short summary; email has the full details with a PDF attached.")}</p>
        {matrix("staff")}
      </section>

      <section>
        <h3 className="text-sm font-semibold text-ink-900">{t("Messages to customers")}</h3>
        <p className="mb-3 text-sm text-ink-600">{t("Used when a message goes out automatically. Staff can still choose other ways when sending by hand.")}</p>
        {matrix("customer")}
      </section>
    </div>
  );
}
