"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useEffect, useState } from "react";
import { ArrowLeft, Megaphone, Pencil, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money } from "@/lib/format";
import { useAction, useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DataTable, DescriptionList, ErrorState, Field, Modal, PageHeader, Panel, Spinner, Stat, Textarea } from "@/components/ui";
import { ChannelPicker } from "@/features/messaging/ChannelPicker";
import { useEnabledChannels, type Channel } from "@/features/messaging/channels";
import { SEGMENTS } from "@/features/marketing/segments";
import { offerText, STATE_LABELS, STATE_TONES, type PromotionDetail } from "@/features/marketing/types";

export default function PromotionPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const router = useRouter();
  const { can } = useAuth();
  const { settings } = usePublicSettings();
  const path = `admin/promotions/${id}`;
  const q = useApi<ApiResponse<PromotionDetail>>(path, undefined, { keepPrevious: false });
  const [notifying, setNotifying] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const remove = useAction(() => api.del(path), { invalidate: [["admin/promotions"]], onSuccess: () => router.push("/admin/promotions") });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Not found.")} onRetry={() => q.refetch()} />;
  const p = q.data.data;
  const audience = p.audience === "all" ? t("All customers")
    : p.audience === "customers" ? p.customers.map((c) => c.name).join(", ")
      : (p.segments ?? []).map((s) => t(SEGMENTS[s]?.label ?? s)).join(", ");
  const scope = p.applies_to === "all" ? t("Everything") : p.applies_to === "products" ? p.products.map((x) => x.name).join(", ") : t("{count} categories", { count: p.category_ids?.length ?? 0 });

  return (
    <Guard perm="marketing.promotions">
      <PageHeader
        back={<Link href="/admin/promotions" className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Promotions")}</Link>}
        title={p.name}
        description={<span className="flex flex-wrap items-center gap-2"><Badge tone={STATE_TONES[p.state]}>{t(STATE_LABELS[p.state])}</Badge><span className="font-semibold text-brand-700">{offerText(p, t, settings?.currency)}</span><span>{p.code}</span></span>}
        actions={
          <>
            {can("marketing.manage") && <Button variant="ghost" className="text-ember-600" icon={<Trash2 className="size-4" />} onClick={() => setDeleting(true)}>{t("Delete")}</Button>}
            {can("marketing.manage") && <Link href={`/admin/promotions/${p.id}/edit`}><Button variant="secondary" icon={<Pencil className="size-4" />}>{t("Edit")}</Button></Link>}
            {can("marketing.notify") && p.state !== "ended" && <Button icon={<Megaphone className="size-4" />} onClick={() => setNotifying(true)}>{t("Tell customers")}</Button>}
          </>
        }
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={t("Customers it is for")} value={p.audience === "all" ? t("Everyone") : p.audience_size.toLocaleString()} />
        <Stat label={t("Times used")} value={(p.redemptions_count ?? 0).toLocaleString()} />
        <Stat label={t("Discount given")} value={money(p.redemptions_sum_amount ?? 0)} tone="saffron" />
        <Stat label={t("Sales with this promotion")} value={money(p.sales_value)} tone="brand" />
      </div>
      <div className="grid gap-5 lg:grid-cols-[360px_1fr]">
        <Panel title={t("Details")}>
          <DescriptionList items={[
            ["Offer", offerText(p, t, settings?.currency)],
            ["On", scope],
            ["For", audience],
            ["Dates", `${date(p.starts_on)} – ${date(p.ends_on)}`],
            ["Minimum purchase", p.min_purchase ? money(p.min_purchase) : "—"],
            ["Largest discount per sale", p.max_discount ? money(p.max_discount) : "—"],
            ["Times each customer can use it", p.uses_per_customer ?? t("No limit")],
            ["Customers last told", p.notified_at ? dateTime(p.notified_at) : t("Not yet")],
          ]} />
          {p.description && <p className="mt-4 whitespace-pre-line text-sm text-ink-700">{p.description}</p>}
        </Panel>
        <Panel title={t("Recent use")} padded={false}>
          <DataTable
            rows={p.recent_redemptions}
            rowKey={(r) => r.id}
            onRowClick={(r) => r.sale && router.push(`/admin/sales/${r.sale.id}`)}
            columns={[
              { key: "sale", header: t("Sale"), render: (r) => <span>{r.sale?.number ?? "—"}<span className="block text-xs text-ink-500">{dateTime(r.created_at)}</span></span> },
              { key: "customer", header: t("Customer"), render: (r) => r.customer?.name ?? t("Customer") },
              { key: "total", header: t("Sale total"), align: "right", render: (r) => <span className="num">{money(r.sale?.total)}</span> },
              { key: "amount", header: t("Discount"), align: "right", render: (r) => <span className="num font-medium text-brand-700">{money(r.amount)}</span> },
            ]}
            empty={{ title: t("Not used yet"), message: t("Cashiers see running promotions at the till and apply them with one tap.") }}
          />
        </Panel>
      </div>
      <NotifyDialog open={notifying} onClose={() => setNotifying(false)} promotion={p} onDone={() => q.refetch()} />
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} title={t("Delete {name}?", { name: p.name })} message={t("It stops being offered at the till. Past sales keep their discount.")} confirmLabel={t("Delete")} loading={remove.isPending} onConfirm={() => remove.mutate(undefined)} />
    </Guard>
  );
}

type Preview = { sms: string; whatsapp: string; email_subject: string; audience: number };

function NotifyDialog({ open, onClose, promotion, onDone }: { open: boolean; onClose: () => void; promotion: PromotionDetail; onDone: () => void }) {
  const t = useT();
  const { channels: enabled } = useEnabledChannels();
  const [channels, setChannels] = useState<Channel[]>([]);
  const [smsText, setSmsText] = useState("");
  const [message, setMessage] = useState("");
  const [wasOpen, setWasOpen] = useState(false);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) { setChannels(enabled); setSmsText(""); setMessage(""); }
  }
  const path = `admin/promotions/${promotion.id}`;
  const texts = useDebounced(JSON.stringify({ sms_text: smsText || null, message: message || null }), 400);
  // The preview builds the real messages on the server (a POST), so it is fetched by hand.
  const [shown, setShown] = useState<Preview | null>(null);
  useEffect(() => {
    if (!open) return;
    let live = true;
    api.post<ApiResponse<Preview>>(`${path}/preview`, JSON.parse(texts)).then((r) => live && setShown(r.data)).catch(() => live && setShown(null));
    return () => { live = false; };
  }, [open, texts, path]);

  const send = useAction(() => api.post(`${path}/notify`, { channels, sms_text: smsText || null, message: message || null }), { onSuccess: () => { onDone(); onClose(); } });

  return (
    <Modal open={open} onClose={onClose} size="lg" title={t("Tell customers about {name}", { name: promotion.name })}
      description={shown ? t("Goes to {count} customers who agreed to receive messages.", { count: shown.audience }) : undefined}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<Megaphone className="size-4" />} loading={send.isPending} disabled={!channels.length} onClick={() => send.mutate(undefined)}>{t("Send now")}</Button></>}>
      <div className="space-y-4">
        <ChannelPicker value={channels} onChange={setChannels} />
        <div className="grid gap-4 md:grid-cols-2">
          <div className="space-y-3">
            <Field label={t("Short SMS (optional)")} hint={t("Empty = the standard wording. {count}/320", { count: smsText.length })}>{(p) => <Textarea {...p} rows={3} maxLength={320} value={smsText} onChange={(e) => setSmsText(e.target.value)} />}</Field>
            <Field label={t("Longer message for WhatsApp and email (optional)")} hint={t("Empty = the standard wording.")}>{(p) => <Textarea {...p} rows={5} maxLength={2000} value={message} onChange={(e) => setMessage(e.target.value)} />}</Field>
          </div>
          <div className="space-y-3 text-sm">
            <p className="font-medium text-ink-700">{t("What customers will get")}</p>
            {shown ? (
              <>
                {channels.includes("sms") && <PreviewBox label="SMS" text={shown.sms} />}
                {channels.includes("whatsapp") && <PreviewBox label="WhatsApp" text={shown.whatsapp} />}
                {channels.includes("email") && <PreviewBox label={t("Email")} text={`${shown.email_subject}\n\n${t("With a PDF flyer of the offer attached.")}`} />}
              </>
            ) : <Spinner variant="compact" />}
          </div>
        </div>
      </div>
    </Modal>
  );
}

function PreviewBox({ label, text }: { label: string; text: string }) {
  return (
    <div className="rounded-lg bg-ink-50 p-3 ring-1 ring-ink-100">
      <p className="mb-1 text-xs font-semibold uppercase text-ink-500">{label}</p>
      <p className="whitespace-pre-line text-ink-800">{text}</p>
    </div>
  );
}
