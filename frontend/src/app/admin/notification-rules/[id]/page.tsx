"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useEffect, useRef, useState } from "react";
import { ArrowLeft, Eye, Play, Save, Trash2, Wand2 } from "lucide-react";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { cn, dateTime } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { useLookups } from "@/lib/lookups";
type Role = { id: number; name: string; is_developer?: boolean; deleted_at?: string | null };
import { Guard } from "@/components/layout/Guard";
import { Button, Checkbox, ConfirmDialog, ErrorState, Field, Input, PageHeader, Panel, Select, Spinner, Switch, Textarea } from "@/components/ui";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import { FREQUENCY_LABELS, PERIOD_LABELS, WEEKDAY_LABELS, type Frequency, type NotificationRule, type RulePreview, type Source } from "@/features/notification-rules/types";

type Form = Omit<NotificationRule, "id" | "next_run_at" | "last_run_at" | "last_result" | "role_ids"> & { role_ids: number[] };
type TemplateKey = "sms_template" | "whatsapp_template" | "email_subject" | "email_template";

const blank: Form = {
  name: "", source: "sales_summary", branch_id: null, channels: ["sms", "email"], role_ids: [], send_to_role_users: true, include_company_contacts: false,
  extra_phones: "", extra_whatsapp: "", extra_emails: "", frequency: "daily", time: "19:00", weekday: 1, month_day: 1, run_at: null, period: "today",
  skip_when_empty: true, sms_template: "", whatsapp_template: "", email_subject: "", email_template: "", is_active: true,
};

export default function NotificationRulePage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const isNew = id === "new";
  const q = useApi<ApiResponse<NotificationRule>>(isNew ? null : `admin/notification-rules/${id}`, undefined, { keepPrevious: false });
  if (!isNew && q.isLoading) return <Spinner variant="page" />;
  if (!isNew && (q.error || !q.data)) return <ErrorState message={q.error?.message ?? t("Not found.")} onRetry={() => q.refetch()} />;
  return <Guard perm="settings.notifications"><RuleEditor existing={q.data?.data} /></Guard>;
}

function RuleEditor({ existing }: { existing?: NotificationRule }) {
  const t = useT();
  const router = useRouter();
  const sources = useApi<ApiResponse<Source[]>>("admin/notification-rules/sources");
  const roles = useApi<ApiResponse<Role[]>>("admin/roles");
  const { branches = [] } = useLookups(["branches"]);
  const [f, setF] = useState<Form>(() => existing ? { ...blank, ...existing, role_ids: existing.role_ids ?? [], time: existing.time.slice(0, 5), run_at: existing.run_at?.slice(0, 16) ?? null } : blank);
  const [deleting, setDeleting] = useState(false);
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((x) => ({ ...x, [k]: v }));
  const source = sources.data?.data.find((s) => s.key === f.source);

  // Placeholders go into whichever wording box was used last.
  const lastBox = useRef<TemplateKey>("sms_template");
  const boxes = useRef<Partial<Record<TemplateKey, HTMLTextAreaElement | HTMLInputElement | null>>>({});
  const insert = (name: string) => {
    const k = lastBox.current;
    const el = boxes.current[k];
    const current = f[k] ?? "";
    const at = el?.selectionStart ?? current.length;
    set(k, `${current.slice(0, at)}{${name}}${current.slice(el?.selectionEnd ?? at)}`);
    setTimeout(() => { el?.focus(); el?.setSelectionRange(at + name.length + 2, at + name.length + 2); }, 0);
  };
  const fillDefaults = () => source && setF((x) => ({ ...x, sms_template: source.defaults.sms, whatsapp_template: source.defaults.whatsapp, email_subject: source.defaults.subject, email_template: source.defaults.email }));

  const body = () => ({
    ...f,
    time: f.frequency === "hourly" ? `00:${f.time.slice(3, 5) || "00"}` : f.time,
    weekday: f.frequency === "weekly" ? f.weekday : null,
    month_day: f.frequency === "monthly" ? f.month_day : null,
    run_at: f.frequency === "once" ? f.run_at : null,
    extra_phones: f.extra_phones || null, extra_whatsapp: f.extra_whatsapp || null, extra_emails: f.extra_emails || null,
    sms_template: f.sms_template || null, whatsapp_template: f.whatsapp_template || null, email_subject: f.email_subject || null, email_template: f.email_template || null,
  });

  const save = useAction(() => existing ? api.put<ApiResponse<NotificationRule>>(`admin/notification-rules/${existing.id}`, body()) : api.post<ApiResponse<NotificationRule>>("admin/notification-rules", body()), {
    invalidate: [["admin/notification-rules"], existing ? [`admin/notification-rules/${existing.id}`] : ["admin/notification-rules"]],
    onSuccess: (r) => { if (!existing) router.replace(`/admin/notification-rules/${r.data.id}`); },
  });
  const run = useAction(() => api.post(`admin/notification-rules/${existing!.id}/run`), { invalidate: [[`admin/notification-rules/${existing?.id}`]] });
  const remove = useAction(() => api.del(`admin/notification-rules/${existing!.id}`), { invalidate: [["admin/notification-rules"]], onSuccess: () => router.push("/admin/notification-rules") });
  const e = fieldErrors(save.error);

  // Live preview with today's figures, from the form as it is now.
  const [preview, setPreview] = useState<RulePreview | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const previewBody = useDebounced(JSON.stringify(body()), 600);
  useEffect(() => {
    const b = JSON.parse(previewBody) as Form;
    if (!b.name.trim()) b.name = "Preview";
    let live = true;
    api.post<ApiResponse<RulePreview>>("admin/notification-rules/preview", b)
      .then((r) => { if (live) { setPreview(r.data); setPreviewError(null); } })
      .catch((err) => { if (live) setPreviewError(err instanceof ApiError ? Object.values(err.errors)[0]?.[0] ?? err.message : t("Could not build the preview.")); });
    return () => { live = false; };
  }, [previewBody, t]);

  const variables = source ? Object.entries(source.variables) : [];
  const template = (k: TemplateKey, label: string, def: string | undefined, rows: number, max: number) => (
    <Field label={label} error={e[k]} hint={f[k] ? t("{count}/{max}", { count: (f[k] ?? "").length, max }) : t("Empty = the default wording")}>
      {(p) => rows === 1
        ? <Input {...p} ref={(el) => { boxes.current[k] = el; }} value={f[k] ?? ""} placeholder={def} maxLength={max} onFocus={() => (lastBox.current = k)} onChange={(ev) => set(k, ev.target.value)} />
        : <Textarea {...p} ref={(el) => { boxes.current[k] = el; }} rows={rows} value={f[k] ?? ""} placeholder={def} maxLength={max} onFocus={() => (lastBox.current = k)} onChange={(ev) => set(k, ev.target.value)} />}
    </Field>
  );

  return (
    <>
      <PageHeader
        back={<Link href="/admin/notification-rules" className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Automatic notifications")}</Link>}
        title={existing ? existing.name : t("New notification")}
        description={existing ? (existing.is_active && existing.next_run_at ? t("Next: {date}", { date: dateTime(existing.next_run_at) }) : t("Switched off")) + (existing.last_run_at ? ` · ${t("Last sent {date}", { date: dateTime(existing.last_run_at) })}` : "") : undefined}
        actions={
          <>
            {existing && <Button variant="ghost" className="text-ember-600" icon={<Trash2 className="size-4" />} onClick={() => setDeleting(true)}>{t("Delete")}</Button>}
            {existing && <Button variant="secondary" icon={<Play className="size-4" />} loading={run.isPending} onClick={() => run.mutate(undefined)}>{t("Send now")}</Button>}
            <Button icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save")}</Button>
          </>
        }
      />

      <div className="grid gap-5 xl:grid-cols-[1fr_400px]">
        <div className="space-y-5">
          <Panel title={t("What")}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t("Name")} required error={e.name}>{(p) => <Input {...p} value={f.name} onChange={(ev) => set("name", ev.target.value)} placeholder={t("e.g. Evening sales report")} />}</Field>
              <Field label={t("What to report")} required error={e.source}>
                {(p) => <Select {...p} value={f.source} onChange={(ev) => set("source", ev.target.value)}>{sources.data?.data.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}</Select>}
              </Field>
              <Field label={t("Figures for")} error={e.period}>
                {(p) => <Select {...p} value={f.period} onChange={(ev) => set("period", ev.target.value)}>{Object.entries(PERIOD_LABELS).map(([k, l]) => <option key={k} value={k}>{t(l)}</option>)}</Select>}
              </Field>
              <Field label={t("Branch")} error={e.branch_id}>
                {(p) => <Select {...p} value={f.branch_id ?? ""} onChange={(ev) => set("branch_id", ev.target.value ? Number(ev.target.value) : null)}>
                  <option value="">{t("All branches")}</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </Select>}
              </Field>
            </div>
            <div className="mt-4 space-y-3">
              <Switch label={t("Don't send when there is nothing to report")} description={t("e.g. no low stock today: nobody is disturbed.")} checked={f.skip_when_empty} onChange={(v) => set("skip_when_empty", v)} />
              <Switch label={t("Active")} checked={f.is_active} onChange={(v) => set("is_active", v)} />
            </div>
          </Panel>

          <Panel title={t("Who receives it")}>
            <fieldset>
              <legend className="mb-1.5 text-[13px] font-medium text-ink-700">{t("Roles")}</legend>
              <div className="grid gap-1.5 sm:grid-cols-3">
                {roles.data?.data.filter((r) => !r.is_developer && !r.deleted_at).map((r) => (
                  <Checkbox key={r.id} label={r.name} checked={f.role_ids.includes(r.id)} onChange={() => set("role_ids", f.role_ids.includes(r.id) ? f.role_ids.filter((x) => x !== r.id) : [...f.role_ids, r.id])} />
                ))}
              </div>
              <p className="mt-1 text-xs text-ink-500">{f.role_ids.length ? t("People in these roles see it in the bell.") : t("No role chosen: everyone allowed to see these figures gets it in the bell.")}</p>
              {e.role_ids && <p className="mt-1 text-xs text-ember-600">{e.role_ids}</p>}
            </fieldset>
            <div className="mt-4 space-y-3">
              <Switch label={t("Also send to their own phone and email")} description={t("Uses the phone number and email saved on each person's account.")} checked={f.send_to_role_users} onChange={(v) => set("send_to_role_users", v)} />
              <Switch label={t("Also send to the company's notification contacts")} description={t("The numbers and emails in Settings → Messages.")} checked={f.include_company_contacts} onChange={(v) => set("include_company_contacts", v)} />
            </div>
            <div className="mt-4 grid gap-4 sm:grid-cols-3">
              <Field label={t("Extra SMS numbers")} hint={t("Separate with commas")} error={e.extra_phones}>{(p) => <Textarea {...p} rows={2} value={f.extra_phones ?? ""} onChange={(ev) => set("extra_phones", ev.target.value)} placeholder="0712 345 678, 0755 ..." />}</Field>
              <Field label={t("Extra WhatsApp numbers")} hint={t("Separate with commas")} error={e.extra_whatsapp}>{(p) => <Textarea {...p} rows={2} value={f.extra_whatsapp ?? ""} onChange={(ev) => set("extra_whatsapp", ev.target.value)} />}</Field>
              <Field label={t("Extra emails")} hint={t("Separate with commas")} error={e.extra_emails}>{(p) => <Textarea {...p} rows={2} value={f.extra_emails ?? ""} onChange={(ev) => set("extra_emails", ev.target.value)} />}</Field>
            </div>
            <div className="mt-4">
              <ChannelPicker value={f.channels} onChange={(v) => set("channels", v)} label={t("Send by (none = bell only)")} />
            </div>
          </Panel>

          <Panel title={t("When")}>
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label={t("How often")} error={e.frequency}>
                {(p) => <Select {...p} value={f.frequency} onChange={(ev) => set("frequency", ev.target.value as Frequency)}>{Object.entries(FREQUENCY_LABELS).map(([k, l]) => <option key={k} value={k}>{t(l)}</option>)}</Select>}
              </Field>
              {f.frequency === "weekly" && (
                <Field label={t("Day")} error={e.weekday}>{(p) => <Select {...p} value={f.weekday ?? 1} onChange={(ev) => set("weekday", Number(ev.target.value))}>{WEEKDAY_LABELS.map((d, i) => <option key={d} value={i}>{t(d)}</option>)}</Select>}</Field>
              )}
              {f.frequency === "monthly" && (
                <Field label={t("Day of the month")} error={e.month_day}>
                  {(p) => <Select {...p} value={f.month_day ?? 1} onChange={(ev) => set("month_day", Number(ev.target.value))}>
                    {Array.from({ length: 28 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}<option value={0}>{t("Last day")}</option>
                  </Select>}
                </Field>
              )}
              {f.frequency === "once" ? (
                <Field label={t("Date and time")} error={e.run_at}>{(p) => <Input {...p} type="datetime-local" value={f.run_at ?? ""} onChange={(ev) => set("run_at", ev.target.value)} />}</Field>
              ) : f.frequency === "hourly" ? (
                <Field label={t("At minute")} error={e.time}>{(p) => <Input {...p} type="number" min={0} max={59} value={Number(f.time.slice(3, 5))} onChange={(ev) => set("time", `00:${String(Math.min(59, Math.max(0, Number(ev.target.value) || 0))).padStart(2, "0")}`)} />}</Field>
              ) : (
                <Field label={t("Time")} error={e.time}>{(p) => <Input {...p} type="time" value={f.time} onChange={(ev) => set("time", ev.target.value)} />}</Field>
              )}
            </div>
          </Panel>

          <Panel title={t("Wording")} actions={source && <Button size="sm" variant="ghost" icon={<Wand2 className="size-4" />} onClick={fillDefaults}>{t("Start from the default wording")}</Button>}>
            {variables.length > 0 && (
              <div className="mb-4">
                <p className="mb-1.5 text-xs text-ink-500">{t("Tap a value to put it where you are typing:")}</p>
                <div className="flex flex-wrap gap-1.5">
                  {variables.map(([k, label]) => (
                    <button key={k} type="button" onClick={() => insert(k)} title={label} className="rounded-md bg-ink-100 px-2 py-0.5 font-mono text-xs text-ink-700 hover:bg-brand-50 hover:text-brand-800">{`{${k}}`}<span className="ml-1 font-sans text-ink-500">{label}</span></button>
                  ))}
                </div>
              </div>
            )}
            <div className="grid gap-4">
              {template("sms_template", t("SMS (short)"), source?.defaults.sms, 2, 640)}
              {template("whatsapp_template", t("WhatsApp"), source?.defaults.whatsapp, 5, 4000)}
              {template("email_subject", t("Email subject"), source?.defaults.subject, 1, 200)}
              {template("email_template", t("Email opening text (the full figures follow, with a PDF)"), source?.defaults.email, 3, 4000)}
            </div>
          </Panel>
        </div>

        <div className="space-y-5">
          <Panel title={<span className="flex items-center gap-1.5"><Eye className="size-4" /> {t("Preview with today's figures")}</span>} className="xl:sticky xl:top-20">
            {previewError && <p className="mb-3 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{previewError}</p>}
            {!preview ? <Spinner variant="compact" /> : (
              <div className="space-y-3 text-sm">
                {preview.empty && <p className="rounded-md bg-saffron-50 px-3 py-2 text-saffron-800">{f.skip_when_empty ? t("Nothing to report right now, so it would not be sent.") : t("Nothing to report right now.")}</p>}
                {f.channels.includes("sms") && <Bubble label="SMS" text={preview.sms} />}
                {f.channels.includes("whatsapp") && <Bubble label="WhatsApp" text={preview.whatsapp} />}
                {f.channels.includes("email") && <Bubble label={t("Email")} text={`${preview.email_subject}\n\n${preview.email_intro}\n\n${t("+ the figures in a table and a PDF.")}`} />}
                <div className="rounded-md p-3 ring-1 ring-ink-200">
                  <p className="mb-1 text-xs font-semibold uppercase text-ink-500">{t("Goes to")}</p>
                  <Recipients label="SMS" list={f.channels.includes("sms") ? preview.recipients.sms : []} />
                  <Recipients label="WhatsApp" list={f.channels.includes("whatsapp") ? preview.recipients.whatsapp : []} />
                  <Recipients label={t("Email")} list={f.channels.includes("email") ? preview.recipients.emails : []} />
                  <p className="mt-1 text-xs text-ink-500">{t("+ the bell of everyone in the chosen roles.")}</p>
                </div>
              </div>
            )}
          </Panel>
        </div>
      </div>
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} title={t("Delete this notification?")} message={t("It will not be sent any more.")} confirmLabel={t("Delete")} loading={remove.isPending} onConfirm={() => remove.mutate(undefined)} />
    </>
  );
}

function Bubble({ label, text }: { label: string; text: string }) {
  return (
    <div className="rounded-lg bg-ink-50 p-3 ring-1 ring-ink-100">
      <p className="mb-1 text-xs font-semibold uppercase text-ink-500">{label}</p>
      <p className="whitespace-pre-line text-ink-800">{text}</p>
    </div>
  );
}

function Recipients({ label, list }: { label: string; list: string[] }) {
  const t = useT();
  return (
    <p className={cn("text-sm", !list.length && "text-ink-400")}>
      <span className="font-medium">{label}:</span> {list.length ? list.slice(0, 6).join(", ") + (list.length > 6 ? t(" and {count} more", { count: list.length - 6 }) : "") : t("nobody")}
    </p>
  );
}
