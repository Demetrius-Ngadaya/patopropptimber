"use client";

import { useState } from "react";
import { Paperclip, Send } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, humanize } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { CustomerPicker } from "@/features/crm/components/CustomerPicker";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import { CHANNELS, useEnabledChannels, type Channel } from "@/features/messaging/channels";
import { Badge, Button, DataTable, Field, Input, PageHeader, Pagination, Panel, SearchInput, Select, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Log = { id: number; channel: Channel; recipient: string; subject: string | null; message: string; has_attachment: boolean; purpose: string; status: string; response: string | null; created_at: string };
type Picked = { id: number; name: string; phone?: string | null };

const PURPOSES = ["manual", "receipt", "debt_reminder", "order_status", "report", "low_stock", "out_of_stock", "stock_digest", "debt_overdue", "new_order", "sale_cancelled", "leave_request", "payroll_prepared"];

/** Send a message by SMS, WhatsApp and/or email, and see everything the system has sent. */
export default function MessagesPage() {
  const t = useT();
  const { can } = useAuth();
  const { channels: enabled } = useEnabledChannels();
  const [audience, setAudience] = useState<"numbers" | "customers" | "debtors">("numbers");
  const [channels, setChannels] = useState<Channel[]>(["sms"]);
  const [numbers, setNumbers] = useState("");
  const [emails, setEmails] = useState("");
  const [customers, setCustomers] = useState<Picked[]>([]);
  const [subject, setSubject] = useState("");
  const [message, setMessage] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [purpose, setPurpose] = useState("");
  const [channel, setChannel] = useState("");
  const [page, setPage] = useState(1);
  const q = useDebounced(search);
  const logs = useApi<Paginated<Log>>(can("sms.logs") ? "admin/audit/sms" : null, { search: q, status, purpose, channel, page });

  const chosen = channels.filter((c) => enabled.includes(c));
  const sms = chosen.includes("sms");
  const phones = chosen.includes("sms") || chosen.includes("whatsapp");
  const email = chosen.includes("email");

  const send = useAction(
    () => api.post("admin/sms/send", {
      audience,
      channels: chosen,
      numbers: audience === "numbers" && phones ? numbers : null,
      emails: audience === "numbers" && email ? emails : null,
      customer_ids: audience === "customers" ? customers.map((c) => c.id) : null,
      subject: email ? subject || null : null,
      message,
    }),
    { invalidate: [["admin/audit/sms"]], onSuccess: () => { setMessage(""); setSubject(""); setNumbers(""); setEmails(""); setCustomers([]); } },
  );
  const errors = fieldErrors(send.error);
  const parts = Math.max(1, Math.ceil(message.length / 160));
  const max = sms ? 640 : 2000;
  const nothingToSend = audience === "numbers" && !((phones && numbers.trim()) || (email && emails.trim()));

  return (
    <Guard perm={["sms.send", "sms.logs"]}>
      <PageHeader title={t("Messages")} description={t("Message customers by SMS, WhatsApp or email, and see every message the system has sent.")} />
      <div className="grid gap-5 lg:grid-cols-[400px_1fr]">
        {can("sms.send") && (
          <Panel title={t("Send a message")} className="lg:self-start">
            <div className="space-y-4">
              <ChannelPicker value={chosen} onChange={setChannels} />
              <Field label={t("Send to")}>{(p) => (
                <Select {...p} value={audience} onChange={(e) => setAudience(e.target.value as typeof audience)}>
                  <option value="numbers">{t("Numbers and emails I type")}</option>
                  <option value="customers">{t("Chosen customers")}</option>
                  <option value="debtors">{t("All customers who owe money")}</option>
                </Select>
              )}</Field>
              {audience === "numbers" && phones && <Field label={t("Phone numbers")} error={errors.numbers} hint={t("Separate with commas or new lines, e.g. 0712 345 678.")}>{(p) => <Textarea {...p} rows={2} value={numbers} onChange={(e) => setNumbers(e.target.value)} />}</Field>}
              {audience === "numbers" && email && <Field label={t("Email addresses")} error={errors.emails} hint={t("Separate with commas.")}>{(p) => <Textarea {...p} rows={2} value={emails} onChange={(e) => setEmails(e.target.value)} />}</Field>}
              {audience === "customers" && (
                <div className="space-y-2">
                  <CustomerPicker value={null} onChange={(c) => c && !customers.some((x) => x.id === c.id) && setCustomers([...customers, c])} />
                  {errors.customer_ids && <p className="text-sm text-ember-600">{errors.customer_ids}</p>}
                  <div className="flex flex-wrap gap-1.5">
                    {customers.map((c) => <button key={c.id} type="button" className="rounded-full bg-ink-100 px-2.5 py-1 text-xs hover:bg-ember-50 hover:text-ember-700" onClick={() => setCustomers(customers.filter((x) => x.id !== c.id))} title={t("Remove")}>{c.name} ×</button>)}
                  </div>
                </div>
              )}
              {audience !== "numbers" && <p className="text-xs text-ink-500">{t("Only customers who have agreed to receive messages are included. By email, customers who owe money also get their unpaid invoices as a PDF.")}</p>}
              {email && <Field label={t("Email subject")} error={errors.subject}>{(p) => <Input {...p} maxLength={150} value={subject} onChange={(e) => setSubject(e.target.value)} placeholder={t("Message from the business")} />}</Field>}
              <Field label={t("Message")} required error={errors.message} hint={sms ? t("{count}/640 characters · {parts} SMS per person", { count: message.length, parts }) : t("{count}/{max} characters", { count: message.length, max })}>
                {(p) => <Textarea {...p} rows={5} maxLength={max} value={message} onChange={(e) => setMessage(e.target.value)} />}
              </Field>
              <Button className="w-full" icon={<Send className="size-4" />} loading={send.isPending} disabled={message.trim().length < 2 || !chosen.length || nothingToSend || (audience === "customers" && !customers.length)} onClick={() => send.mutate(undefined)}>{t("Send")}</Button>
            </div>
          </Panel>
        )}
        {can("sms.logs") && (
          <Panel padded={false} title={t("Message log")}>
            <div className="flex flex-wrap items-center gap-3 border-b border-ink-100 p-3">
              <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Number, email or text")} className="w-full max-w-xs" />
              <Select aria-label={t("Sent by")} className="w-36" value={channel} onChange={(e) => { setChannel(e.target.value); setPage(1); }}><option value="">{t("All channels")}</option>{CHANNELS.map((c) => <option key={c.value} value={c.value}>{t(c.label)}</option>)}</Select>
              <Select aria-label={t("Status")} className="w-36" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}><option value="">{t("All results")}</option><option value="sent">{t("Sent")}</option><option value="failed">{t("Failed")}</option><option value="disabled">{t("Not set up")}</option></Select>
              <Select aria-label={t("Purpose")} className="w-44" value={purpose} onChange={(e) => { setPurpose(e.target.value); setPage(1); }}><option value="">{t("All purposes")}</option>{PURPOSES.map((p) => <option key={p} value={p}>{t(humanize(p))}</option>)}</Select>
            </div>
            <DataTable rows={logs.data?.data} loading={logs.isFetching} rowKey={(r) => r.id} columns={[
              { key: "created_at", header: t("When"), render: (r) => <span className="whitespace-nowrap">{dateTime(r.created_at)}</span> },
              { key: "channel", header: t("Sent by"), render: (r) => { const c = CHANNELS.find((x) => x.value === r.channel); return c ? <span className="inline-flex items-center gap-1.5 whitespace-nowrap"><c.icon className="size-4 text-ink-500" />{t(c.label)}</span> : r.channel; } },
              { key: "recipient", header: t("To"), render: (r) => <span className="num break-all">{r.recipient}</span> },
              { key: "message", header: t("Message"), render: (r) => <span className="block max-w-md">{r.subject && <span className="flex items-center gap-1 font-medium text-ink-900">{r.has_attachment && <Paperclip className="size-3.5 shrink-0" aria-label={t("PDF attached")} />}{r.subject}</span>}<span className="line-clamp-2" title={r.message}>{r.message}</span></span> },
              { key: "purpose", header: t("Purpose"), hideOnMobile: true, render: (r) => t(humanize(r.purpose)) },
              { key: "status", header: t("Result"), render: (r) => <span title={r.status === "sent" ? undefined : r.response ?? undefined}><Badge tone={r.status === "sent" ? "green" : r.status === "failed" ? "red" : "neutral"}>{r.status === "disabled" ? t("Not set up") : t(humanize(r.status))}</Badge></span> },
            ]} empty={{ title: t("No messages yet") }} />
            <Pagination meta={logs.data?.meta} onPage={setPage} />
          </Panel>
        )}
      </div>
    </Guard>
  );
}
