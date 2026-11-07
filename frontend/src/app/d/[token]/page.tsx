"use client";

import { use, useState } from "react";
import { CheckCircle2, Download, XCircle } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { cn, date, money, qty } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { BRAND } from "@/lib/brand";
import { LanguageSwitch } from "@/components/layout/PreferenceSwitches";
import { Badge, Button, ErrorState, Field, Modal, Spinner, Textarea } from "@/components/ui";
import { STAGE_LABELS, STAGE_TONES } from "@/features/documents/types";

type Brand = { name: string; phone: string | null; whatsapp: string | null; email: string | null; website: string | null; address: string | null; tin: string | null; vrn: string | null; bank: string | null; color: string | null; logo_url: string | null };
type PublicDocument = {
  brand: Brand;
  currency: string;
  columns: string[];
  type: "quotation" | "proforma" | "invoice";
  title: string;
  number: string;
  stage: string;
  issue_date: string;
  due_date: string | null;
  due_label: string;
  customer: { name: string; phone: string | null; email: string | null; address: string | null; tin: string | null; vrn: string | null };
  items: Array<{ description: string; sku: string | null; unit: string | null; quantity: number; unit_price: number; discount: number; tax_rate: number; tax: number; line_total: number }>;
  totals: { subtotal: number; item_discount: number; discount: number; tax: number; prices_include_tax: boolean; total: number; paid: number | null; balance: number | null };
  amount_in_words: string | null;
  bank_details: string | null;
  terms: string | null;
  notes: string | null;
  footer: string;
  decline_reason: string | null;
  can_respond: boolean;
};

/** The customer's copy of a quotation, proforma or invoice, opened from the link they were sent. */
export default function PublicDocumentPage({ params }: { params: Promise<{ token: string }> }) {
  const t = useT();
  const { token } = use(params);
  const path = `public/documents/${token}`;
  const q = useApi<ApiResponse<PublicDocument>>(path, undefined, { keepPrevious: false });
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState("");
  const respond = useAction((accept: boolean) => api.post(`${path}/respond`, { accept, reason: accept ? null : reason || null }), {
    invalidate: [[path]],
    onSuccess: () => setDeclining(false),
  });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <div className="mx-auto max-w-lg p-6"><ErrorState message={q.error?.status === 404 ? t("This link is not valid any more. Ask the shop to send it again.") : q.error?.message ?? t("Not found.")} /></div>;
  const d = q.data.data;
  const b = d.brand;
  const color = b.color || "#157a55";
  const show = (c: string) => d.columns.includes(c);
  const contacts = [b.phone && `${t("Tel")} ${b.phone}`, b.whatsapp && `WhatsApp ${b.whatsapp}`, b.email, b.website].filter(Boolean).join(" · ");

  return (
    <div className="min-h-dvh bg-ink-100 px-3 py-6 sm:py-10">
      <div className="mx-auto mb-3 flex max-w-3xl flex-wrap items-center justify-between gap-2">
        <Badge tone={STAGE_TONES[d.stage] ?? "neutral"}>{t(STAGE_LABELS[d.stage] ?? d.stage)}</Badge>
        <div className="flex items-center gap-2">
          <LanguageSwitch compact />
          <a href={`/api/backend/${path}/pdf`}><Button variant="secondary" icon={<Download className="size-4" />}>{t("Download PDF")}</Button></a>
        </div>
      </div>

      {d.can_respond && (
        <div className="mx-auto mb-4 flex max-w-3xl flex-col gap-3 rounded-xl bg-surface p-4 shadow-sm ring-1 ring-ink-200 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-ink-700">{t("Do you accept this quotation? Your answer goes straight to {business}.", { business: b.name })}</p>
          <div className="flex gap-2">
            <Button variant="secondary" icon={<XCircle className="size-4" />} onClick={() => setDeclining(true)}>{t("Decline")}</Button>
            <Button icon={<CheckCircle2 className="size-4" />} loading={respond.isPending && respond.variables === true} onClick={() => respond.mutate(true)}>{t("Accept")}</Button>
          </div>
        </div>
      )}
      {d.stage === "accepted" && <p className="mx-auto mb-4 max-w-3xl rounded-xl bg-brand-50 p-3 text-sm text-brand-800 ring-1 ring-brand-100">{t("You accepted this quotation. {business} will be in touch.", { business: b.name })}</p>}

      <article className="keep-light mx-auto max-w-3xl rounded-xl bg-white p-5 text-[13.5px] text-[#13213b] shadow-sm ring-1 ring-ink-200 sm:p-8">
        <header className="flex flex-col gap-4 sm:flex-row sm:justify-between">
          <div>
            {/* eslint-disable-next-line @next/next/no-img-element -- logo comes from the API storage */}
            {b.logo_url && <img src={b.logo_url} alt="" className="mb-2 h-12 w-auto object-contain" />}
            <p className="text-lg font-bold" style={{ color }}>{b.name}</p>
            {b.address && <p>{b.address}</p>}
            {contacts && <p>{contacts}</p>}
            {b.tin && <p>TIN: <strong>{b.tin}</strong></p>}
            {b.vrn && <p>VRN: <strong>{b.vrn}</strong></p>}
          </div>
          <div className="sm:text-right">
            <p className="text-2xl font-bold uppercase tracking-wide" style={{ color }}>{d.title}</p>
            <p className="mt-1">{t("Number")}: <strong>{d.number}</strong></p>
            <p>{t("Date")}: {date(d.issue_date)}</p>
            {d.due_date && <p>{d.due_label}: <strong>{date(d.due_date)}</strong></p>}
          </div>
        </header>

        <section className="mt-6 rounded-lg border border-[#dde2ea] p-3 sm:w-1/2">
          <p className="text-[11px] uppercase tracking-wide text-[#6b7a99]">{d.type === "quotation" ? t("Prepared for") : t("Bill to")}</p>
          <p className="mt-0.5 font-semibold">{d.customer.name}</p>
          {d.customer.address && <p>{d.customer.address}</p>}
          {(d.customer.phone || d.customer.email) && <p>{[d.customer.phone, d.customer.email].filter(Boolean).join(" · ")}</p>}
          {d.customer.tin && <p>TIN: {d.customer.tin}</p>}
          {d.customer.vrn && <p>VRN: {d.customer.vrn}</p>}
        </section>

        <div className="mt-5 overflow-x-auto">
          <table className="w-full min-w-[480px]">
            <thead>
              <tr className="text-left text-[12px] text-white" style={{ background: color }}>
                <th className="px-2 py-1.5">#</th>
                <th className="px-2 py-1.5">{t("Description")}</th>
                <th className="px-2 py-1.5 text-right">{t("Qty")}</th>
                <th className="px-2 py-1.5 text-right">{t("Unit price")}</th>
                {show("discount") && <th className="px-2 py-1.5 text-right">{t("Discount")}</th>}
                <th className="px-2 py-1.5 text-right">{t("Amount")}</th>
              </tr>
            </thead>
            <tbody>
              {d.items.map((i, n) => (
                <tr key={n} className="border-b border-[#e6e9ef] align-top">
                  <td className="px-2 py-1.5 text-[#6b7a99]">{n + 1}</td>
                  <td className="px-2 py-1.5">{i.description}{i.sku && <span className="block text-[11px] text-[#6b7a99]">{i.sku}</span>}</td>
                  <td className="num px-2 py-1.5 text-right">{qty(i.quantity, show("unit") ? i.unit : null)}</td>
                  <td className="num px-2 py-1.5 text-right">{money(i.unit_price)}</td>
                  {show("discount") && <td className="num px-2 py-1.5 text-right">{i.discount > 0 ? money(i.discount) : ""}</td>}
                  <td className="num px-2 py-1.5 text-right">{money(i.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-3 flex flex-col-reverse gap-4 sm:flex-row sm:justify-between">
          <div className="sm:w-1/2">{d.amount_in_words && <><p className="text-[11px] uppercase text-[#6b7a99]">{t("Amount in words")}</p><p className="italic">{d.amount_in_words}</p></>}</div>
          <dl className="space-y-1 sm:w-64">
            <div className="flex justify-between"><dt className="text-[#6b7a99]">{t("Subtotal")}</dt><dd className="num">{money(d.totals.subtotal)}</dd></div>
            {d.totals.item_discount + d.totals.discount > 0 && <div className="flex justify-between"><dt className="text-[#6b7a99]">{t("Discount")}</dt><dd className="num">-{money(d.totals.item_discount + d.totals.discount)}</dd></div>}
            {d.totals.tax > 0 && <div className="flex justify-between"><dt className="text-[#6b7a99]">{d.totals.prices_include_tax ? t("VAT included") : t("VAT")}</dt><dd className="num">{money(d.totals.tax)}</dd></div>}
            <div className="flex justify-between border-t-2 border-[#13213b] pt-1 text-base font-bold"><dt>{t("Total")} ({d.currency})</dt><dd className="num">{money(d.totals.total)}</dd></div>
            {d.totals.paid !== null && d.totals.paid > 0 && <div className="flex justify-between"><dt className="text-[#6b7a99]">{t("Paid")}</dt><dd className="num">{money(d.totals.paid)}</dd></div>}
            {d.totals.balance !== null && d.type !== "quotation" && !["cancelled"].includes(d.stage) && <div className="flex justify-between font-bold"><dt>{t("Balance due")}</dt><dd className="num">{money(d.totals.balance)}</dd></div>}
          </dl>
        </div>

        {(d.bank_details || b.bank) && d.type !== "quotation" && (
          <section className="mt-5 rounded-lg border border-[#dde2ea] p-3">
            <p className="text-[11px] uppercase text-[#6b7a99]">{t("How to pay")}</p>
            <p className="whitespace-pre-line">{d.bank_details || b.bank}</p>
          </section>
        )}
        {d.terms && <section className="mt-4"><p className="text-[11px] uppercase text-[#6b7a99]">{t("Terms")}</p><p className="whitespace-pre-line">{d.terms}</p></section>}
        {d.notes && <section className="mt-4"><p className="text-[11px] uppercase text-[#6b7a99]">{t("Notes")}</p><p className="whitespace-pre-line">{d.notes}</p></section>}
        {d.stage === "declined" && d.decline_reason && <section className="mt-4"><p className="text-[11px] uppercase text-[#6b7a99]">{t("Reason for declining")}</p><p>{d.decline_reason}</p></section>}
        <p className={cn("mt-8 border-t border-[#e6e9ef] pt-3 text-center text-[11.5px] text-[#6b7a99]")}>{d.footer || `${b.name} · ${d.title} ${d.number}`}</p>
        <p className="mt-1 text-center text-[10.5px] text-[#9aa5bd]">{BRAND.poweredBy}</p>
      </article>

      <Modal open={declining} onClose={() => setDeclining(false)} title={t("Decline the quotation")} size="sm"
        footer={<><Button variant="ghost" onClick={() => setDeclining(false)}>{t("Back")}</Button><Button variant="danger" loading={respond.isPending} onClick={() => respond.mutate(false)}>{t("Decline")}</Button></>}>
        <Field label={t("Tell us why (optional)")}>{(p) => <Textarea {...p} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t("e.g. the price, the delivery date…")} />}</Field>
      </Modal>
    </div>
  );
}
