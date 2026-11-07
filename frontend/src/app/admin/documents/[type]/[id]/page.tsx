"use client";

import Link from "next/link";
import { notFound, useRouter } from "next/navigation";
import { use, useState } from "react";
import { toast } from "sonner";
import {
  ArrowLeft, ArrowRightLeft, Ban, Check, Copy, CreditCard, Download, ExternalLink, FileCheck2, Lock, Pencil, Send, Trash2, X,
} from "lucide-react";
import { api, download, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money, qty, toNumber } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { paymentMethodLabels, useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, ConfirmDialog, DescriptionList, ErrorState, Field, Input, Modal, PageHeader, Panel, Select, Spinner, Textarea } from "@/components/ui";
import { SendDialog } from "@/features/messaging/SendDialog";
import { EVENT_LABELS, isDocType, STAGE_LABELS, STAGE_TONES, TYPE_META, type DocType, type DocumentDetail } from "@/features/documents/types";

type Dialog = null | "send" | "pay" | "cancel" | "decline" | "issue" | "delete" | "close";

export default function DocumentPage({ params }: { params: Promise<{ type: string; id: string }> }) {
  const { type, id } = use(params);
  if (!isDocType(type)) notFound();
  return <Guard perm="documents.view"><DocumentView type={type} id={id} /></Guard>;
}

function DocumentView({ type, id }: { type: DocType; id: string }) {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const path = `admin/documents/${id}`;
  const q = useApi<ApiResponse<DocumentDetail>>(path, undefined, { keepPrevious: false });
  const [dialog, setDialog] = useState<Dialog>(null);
  const close = () => setDialog(null);
  const refresh = [[path], ["admin/documents"]];

  const act = useAction((v: { action: string; body?: unknown }) => api.post<ApiResponse<DocumentDetail>>(`${path}/${v.action}`, v.body ?? {}), { invalidate: refresh, onSuccess: close });
  const convert = useAction((to: DocType) => api.post<ApiResponse<{ id: number; type: DocType }>>(`${path}/convert`, { to }), {
    invalidate: refresh,
    onSuccess: (r) => router.push(`/admin/documents/${r.data.type}/${r.data.id}`),
  });
  const remove = useAction(() => api.del(path), { invalidate: [["admin/documents"]], onSuccess: () => router.push(`/admin/documents/${type}`) });

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Not found.")} onRetry={() => q.refetch()} />;
  const d = q.data.data;
  const meta = TYPE_META[type];
  const stage = d.stage;
  const finished = ["cancelled", "converted", "closed"].includes(stage);

  // What can be done now (the server checks again).
  const canEdit = can("documents.create") && d.editable;
  const canSend = can("documents.create") && !finished && (type !== "invoice" || d.status !== "draft" || can("documents.issue"));
  const canIssue = type === "invoice" && d.status === "draft" && can("documents.issue");
  const canPay = can("documents.receive_payment") && ((type === "invoice" && ["owing", "overdue"].includes(stage)) || (type === "proforma" && ["draft", "awaiting_payment"].includes(d.status)));
  const canAnswer = type === "quotation" && ["draft", "sent"].includes(d.status) && !d.expired && can("documents.create");
  const convertTo: DocType[] = can("documents.create") && !d.converted_to
    ? type === "quotation" && ["draft", "sent", "accepted"].includes(d.status) ? ["proforma", "invoice"] : type === "proforma" && ["draft", "awaiting_payment"].includes(d.status) ? ["invoice"] : []
    : [];
  const canClose = can("documents.create") && ((type === "invoice" && stage === "paid") || (type === "proforma" && d.status === "paid") || (type === "quotation" && ["accepted", "declined", "expired"].includes(stage)));
  const canCancel = can("documents.cancel") && !["cancelled", "closed", "converted", "paid"].includes(d.status) && stage !== "paid";
  const canDelete = can("documents.create") && d.status === "draft";
  const link = d.status !== "draft" ? d.public_url : null;

  return (
    <>
      <PageHeader
        back={<Link href={`/admin/documents/${type}`} className="mb-1 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t(meta.many)}</Link>}
        title={`${d.title} ${d.number}`}
        description={<span className="flex flex-wrap items-center gap-2"><Badge tone={STAGE_TONES[stage] ?? "neutral"}>{t(STAGE_LABELS[stage] ?? stage)}</Badge><span>{d.customer_name}</span><span className="num font-semibold text-ink-800">{money(d.total)}</span></span>}
        actions={
          <>
            {canEdit && <Link href={`/admin/documents/${type}/${d.id}/edit`}><Button variant="secondary" icon={<Pencil className="size-4" />}>{t("Edit")}</Button></Link>}
            <Button variant="secondary" icon={<Download className="size-4" />} onClick={() => download(`${path}/pdf`)}>{t("PDF")}</Button>
            {canIssue && <Button variant="secondary" icon={<FileCheck2 className="size-4" />} onClick={() => setDialog("issue")}>{t("Issue invoice")}</Button>}
            {canPay && <Button variant="secondary" icon={<CreditCard className="size-4" />} onClick={() => setDialog("pay")}>{t("Record payment")}</Button>}
            {canSend && <Button icon={<Send className="size-4" />} onClick={() => setDialog("send")}>{d.sent_at ? t("Send again") : t("Send")}</Button>}
          </>
        }
      />

      {d.status === "draft" && type === "invoice" && (
        <p className="mb-4 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-800 ring-1 ring-saffron-100">{t("This invoice is a draft. Issuing it (or sending it) records the sale, takes the goods out of stock and adds the amount to the customer's debt.")}</p>
      )}
      {d.expired && stage === "expired" && <p className="mb-4 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{t("The validity date has passed. Change the date and send it again if the offer still stands.")}</p>}

      <div className="grid gap-5 xl:grid-cols-[1fr_340px]">
        <div className="space-y-5">
          <Panel padded={false}>
            <div className="grid gap-4 border-b border-ink-100 p-4 sm:grid-cols-2">
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-ink-500">{type === "quotation" ? t("Prepared for") : t("Bill to")}</p>
                <p className="mt-1 font-semibold text-ink-900">{d.customer_id ? <Link className="hover:underline" href={`/admin/customers/${d.customer_id}`}>{d.customer_name}</Link> : d.customer_name}</p>
                <p className="text-sm text-ink-600">{[d.customer_phone, d.customer_email].filter(Boolean).join(" · ")}</p>
                {d.customer_address && <p className="text-sm text-ink-600">{d.customer_address}</p>}
                {(d.customer_tin || d.customer_vrn) && <p className="text-sm text-ink-600">{[d.customer_tin && `TIN ${d.customer_tin}`, d.customer_vrn && `VRN ${d.customer_vrn}`].filter(Boolean).join(" · ")}</p>}
              </div>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:text-right">
                <dt className="text-ink-500">{t("Date")}</dt><dd>{date(d.issue_date)}</dd>
                {d.due_date && <><dt className="text-ink-500">{t(meta.dueLabel)}</dt><dd className="font-medium">{date(d.due_date)}</dd></>}
                <dt className="text-ink-500">{t("Branch")}</dt><dd>{d.branch?.name ?? "—"}</dd>
                {d.creator && <><dt className="text-ink-500">{t("Prepared by")}</dt><dd>{d.creator.name}</dd></>}
              </dl>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-ink-50/60 text-left text-[12.5px] text-ink-600">
                  <tr>
                    <th className="px-4 py-2 font-semibold">{t("Description")}</th>
                    <th className="px-3 py-2 text-right font-semibold">{t("Quantity")}</th>
                    <th className="px-3 py-2 text-right font-semibold">{t("Unit price")}</th>
                    <th className="hidden px-3 py-2 text-right font-semibold sm:table-cell">{t("Discount")}</th>
                    <th className="px-4 py-2 text-right font-semibold">{t("Amount")}</th>
                  </tr>
                </thead>
                <tbody>
                  {d.items?.map((i) => (
                    <tr key={i.id} className="border-t border-ink-100">
                      <td className="px-4 py-2">{i.description}{i.product?.sku && <span className="block text-xs text-ink-500">{i.product.sku}</span>}</td>
                      <td className="num px-3 py-2 text-right">{qty(i.quantity, i.unit_name)}</td>
                      <td className="num px-3 py-2 text-right">{money(i.unit_price)}</td>
                      <td className="num hidden px-3 py-2 text-right sm:table-cell">{toNumber(i.discount) ? money(i.discount) : ""}</td>
                      <td className="num px-4 py-2 text-right font-medium">{money(i.line_total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <dl className="ml-auto max-w-xs space-y-1 border-t border-ink-100 p-4 text-sm">
              <Line label={t("Subtotal")} value={money(d.subtotal)} />
              {toNumber(d.item_discount) + toNumber(d.discount) > 0 && <Line label={t("Discount")} value={`-${money(toNumber(d.item_discount) + toNumber(d.discount))}`} />}
              {toNumber(d.tax) > 0 && <Line label={t("VAT")} value={money(d.tax)} />}
              <div className="flex justify-between border-t border-ink-200 pt-1.5 text-base font-semibold"><dt>{t("Total")}</dt><dd className="num">{money(d.total)}</dd></div>
              {type !== "quotation" && d.status !== "draft" && stage !== "cancelled" && (
                <>
                  <Line label={t("Paid")} value={money(d.amount_paid)} />
                  <div className="flex justify-between font-semibold text-ember-700"><dt>{t("Balance due")}</dt><dd className="num">{money(d.balance)}</dd></div>
                </>
              )}
            </dl>
            {(d.notes || d.terms) && (
              <div className="grid gap-4 border-t border-ink-100 p-4 text-sm sm:grid-cols-2">
                {d.notes && <div><p className="text-xs font-medium uppercase text-ink-500">{t("Notes")}</p><p className="mt-1 whitespace-pre-line text-ink-800">{d.notes}</p></div>}
                {d.terms && <div><p className="text-xs font-medium uppercase text-ink-500">{t("Terms")}</p><p className="mt-1 whitespace-pre-line text-ink-800">{d.terms}</p></div>}
              </div>
            )}
          </Panel>

          {d.payments.length > 0 && (
            <Panel title={t("Payments")} padded={false}>
              <table className="w-full text-sm">
                <tbody>
                  {d.payments.map((p) => (
                    <tr key={p.id} className="border-b border-ink-100 last:border-0">
                      <td className="px-4 py-2">{dateTime(p.paid_at)}<span className="block text-xs text-ink-500">{p.number}</span></td>
                      <td className="px-3 py-2">{t(paymentMethodLabels[p.method] ?? p.method)}{p.provider ? ` · ${p.provider}` : ""}{p.reference ? ` · ${p.reference}` : ""}</td>
                      <td className="num px-4 py-2 text-right font-medium">{money(p.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Panel>
          )}
        </div>

        <div className="space-y-5">
          {(canAnswer || convertTo.length > 0 || canClose || canCancel || canDelete) && (
            <Panel title={t("Next steps")}>
              <div className="flex flex-col gap-2">
                {canAnswer && (
                  <div className="grid grid-cols-2 gap-2">
                    <Button variant="secondary" icon={<Check className="size-4" />} loading={act.isPending} onClick={() => act.mutate({ action: "respond", body: { accept: true } })}>{t("Accepted")}</Button>
                    <Button variant="secondary" icon={<X className="size-4" />} onClick={() => setDialog("decline")}>{t("Declined")}</Button>
                  </div>
                )}
                {convertTo.map((to) => (
                  <Button key={to} variant="secondary" icon={<ArrowRightLeft className="size-4" />} loading={convert.isPending && convert.variables === to} onClick={() => convert.mutate(to)}>
                    {t("Turn into {kind}", { kind: t(TYPE_META[to].one).toLowerCase() })}
                  </Button>
                ))}
                {canClose && <Button variant="secondary" icon={<Lock className="size-4" />} onClick={() => setDialog("close")}>{t("Close (archive)")}</Button>}
                {canCancel && <Button variant="ghost" className="text-ember-600" icon={<Ban className="size-4" />} onClick={() => setDialog("cancel")}>{t("Cancel {kind}", { kind: t(meta.one).toLowerCase() })}</Button>}
                {canDelete && <Button variant="ghost" className="text-ember-600" icon={<Trash2 className="size-4" />} onClick={() => setDialog("delete")}>{t("Delete draft")}</Button>}
              </div>
            </Panel>
          )}

          <Panel title={t("Details")}>
            <DescriptionList items={[
              ["Number", d.number],
              ...(d.sale ? [["Sale", <Link key="s" href={`/admin/sales/${d.sale.id}`} className="text-brand-700 hover:underline">{d.sale.number}</Link>] as [string, React.ReactNode]] : []),
              ...(d.converted_from ? [["Made from", <Link key="f" href={`/admin/documents/${d.converted_from.type}/${d.converted_from.id}`} className="text-brand-700 hover:underline">{d.converted_from.number}</Link>] as [string, React.ReactNode]] : []),
              ...(d.converted_to ? [["Turned into", <Link key="to" href={`/admin/documents/${d.converted_to.type}/${d.converted_to.id}`} className="text-brand-700 hover:underline">{d.converted_to.number}</Link>] as [string, React.ReactNode]] : []),
              ...(d.decline_reason ? [["Reason for declining", d.decline_reason] as [string, React.ReactNode]] : []),
              ...(d.cancel_reason ? [["Reason for cancelling", d.cancel_reason] as [string, React.ReactNode]] : []),
            ]} />
            {link && (
              <div className="mt-4 rounded-md bg-ink-50 p-2.5">
                <p className="text-xs text-ink-500">{t("Customer's link (view, download and answer)")}</p>
                <div className="mt-1 flex items-center gap-1">
                  <code className="min-w-0 flex-1 truncate text-xs text-ink-700">{link}</code>
                  <button type="button" className="rounded p-1 text-ink-500 hover:bg-surface" aria-label={t("Copy link")} onClick={() => navigator.clipboard?.writeText(link).then(() => toast.success(t("Link copied.")))}><Copy className="size-4" /></button>
                  <a href={link} target="_blank" rel="noreferrer" className="rounded p-1 text-ink-500 hover:bg-surface" aria-label={t("Open")}><ExternalLink className="size-4" /></a>
                </div>
              </div>
            )}
          </Panel>

          <Panel title={t("History")}>
            <ol className="relative space-y-3 border-l border-ink-200 pl-4">
              {(d.events ?? []).slice().reverse().map((e) => (
                <li key={e.id} className="text-sm">
                  <span className="absolute -left-[5px] mt-1.5 size-2.5 rounded-full bg-brand-500 ring-2 ring-surface" aria-hidden />
                  <p className="font-medium text-ink-900">{t(EVENT_LABELS[e.event] ?? e.event)}</p>
                  {e.note && <p className="text-ink-600">{e.note}</p>}
                  <p className="text-xs text-ink-500">{dateTime(e.created_at)}{e.user ? ` · ${e.user.name}` : ""}</p>
                </li>
              ))}
            </ol>
          </Panel>
        </div>
      </div>

      <SendDialog
        open={dialog === "send"}
        onClose={close}
        title={t("Send {kind} {number}", { kind: t(meta.one).toLowerCase(), number: d.number })}
        description={type === "invoice" && d.status === "draft" ? t("Sending issues the invoice: the amount becomes the customer's debt.") : undefined}
        endpoint={`${path}/send`}
        phone={d.customer_phone}
        email={d.customer_email}
        editableContacts
        defaults={["email", "whatsapp", "sms"]}
        note={t("Email carries the PDF, WhatsApp the link to view and download it, and SMS a short note that it was sent.")}
        onSent={() => q.refetch()}
      />
      <PaymentDialog open={dialog === "pay"} onClose={close} doc={d} loading={act.isPending} error={act.error} onPay={(body) => act.mutate({ action: "payments", body })} />
      <ReasonDialog open={dialog === "cancel"} onClose={close} title={t("Cancel {number}", { number: d.number })}
        description={d.status === "issued" ? t("The sale behind this invoice is cancelled too: the goods go back into stock and the debt is removed.") : undefined}
        label={t("Reason")} required loading={act.isPending} confirm={t("Cancel it")} onConfirm={(reason) => act.mutate({ action: "cancel", body: { reason } })} />
      <ReasonDialog open={dialog === "decline"} onClose={close} title={t("The customer declined")} label={t("Reason (optional)")} loading={act.isPending} confirm={t("Mark as declined")}
        onConfirm={(reason) => act.mutate({ action: "respond", body: { accept: false, reason: reason || null } })} />
      <ConfirmDialog open={dialog === "issue"} onClose={close} title={t("Issue invoice {number}?", { number: d.number })}
        message={t("The sale is recorded, the goods leave stock and {customer} will owe {amount}.", { customer: d.customer_name, amount: money(d.total) })}
        confirmLabel={t("Issue invoice")} tone="primary" loading={act.isPending} onConfirm={() => act.mutate({ action: "issue" })} />
      <ConfirmDialog open={dialog === "close"} onClose={close} title={t("Close {number}?", { number: d.number })} message={t("It moves to Closed and can no longer be changed.")}
        confirmLabel={t("Close")} tone="primary" loading={act.isPending} onConfirm={() => act.mutate({ action: "close" })} />
      <ConfirmDialog open={dialog === "delete"} onClose={close} title={t("Delete this draft?")} message={t("The draft is removed. Its number is not reused.")}
        confirmLabel={t("Delete")} tone="danger" loading={remove.isPending} onConfirm={() => remove.mutate(undefined)} />
    </>
  );
}

function Line({ label, value }: { label: string; value: string }) {
  return <div className="flex justify-between"><dt className="text-ink-600">{label}</dt><dd className="num">{value}</dd></div>;
}

function PaymentDialog({ open, onClose, doc, onPay, loading, error }: { open: boolean; onClose: () => void; doc: DocumentDetail; onPay: (body: unknown) => void; loading: boolean; error: unknown }) {
  const t = useT();
  const { payment } = useLookups(["payment"]);
  const full = doc.type === "proforma";
  const [f, setF] = useState({ amount: "", method: "cash", provider: "", reference: "" });
  const [opened, setOpened] = useState(false);
  if (open && !opened) {
    setOpened(true);
    setF({ amount: String(full ? toNumber(doc.total) : toNumber(doc.balance)), method: "cash", provider: "", reference: "" });
  }
  if (!open && opened) setOpened(false);
  const e = fieldErrors(error);
  const methods = (payment?.methods ?? ["cash", "mobile_money", "lipa_namba", "bank", "card", "cheque"]).filter((m) => m !== "savings" && m !== "credit_note");
  const needsProvider = ["mobile_money", "lipa_namba", "bank"].includes(f.method);

  return (
    <Modal open={open} onClose={onClose} title={t("Record payment for {number}", { number: doc.number })}
      description={full ? t("A proforma is paid in full. The paid proforma becomes a completed sale.") : t("Balance due: {amount}", { amount: money(doc.balance) })}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button loading={loading} onClick={() => onPay({ amount: toNumber(f.amount), method: f.method, provider: f.provider || null, reference: f.reference || null })}>{t("Record payment")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("Amount")} required error={e.amount}>{(p) => <Input {...p} type="number" min={0} step="any" value={f.amount} readOnly={full} onChange={(ev) => setF({ ...f, amount: ev.target.value })} />}</Field>
        <Field label={t("Method")} required error={e.method}>{(p) => <Select {...p} value={f.method} onChange={(ev) => setF({ ...f, method: ev.target.value })}>{methods.map((m) => <option key={m} value={m}>{t(paymentMethodLabels[m] ?? m)}</option>)}</Select>}</Field>
        {needsProvider && (
          <Field label={t("Provider")} error={e.provider}>{(p) => <Select {...p} value={f.provider} onChange={(ev) => setF({ ...f, provider: ev.target.value })}><option value="">—</option>{(payment?.providers ?? []).map((x) => <option key={x} value={x}>{x}</option>)}</Select>}</Field>
        )}
        <Field label={t("Reference")} error={e.reference} className={needsProvider ? undefined : "sm:col-span-2"}>{(p) => <Input {...p} value={f.reference} onChange={(ev) => setF({ ...f, reference: ev.target.value })} />}</Field>
      </div>
    </Modal>
  );
}

function ReasonDialog({ open, onClose, title, description, label, required, confirm, loading, onConfirm }: {
  open: boolean; onClose: () => void; title: string; description?: string; label: string; required?: boolean; confirm: string; loading: boolean; onConfirm: (reason: string) => void;
}) {
  const t = useT();
  const [reason, setReason] = useState("");
  return (
    <Modal open={open} onClose={onClose} title={title} description={description} size="sm"
      footer={<><Button variant="ghost" onClick={onClose}>{t("Back")}</Button><Button variant="danger" loading={loading} disabled={required && reason.trim().length < 3} onClick={() => onConfirm(reason.trim())}>{confirm}</Button></>}>
      <Field label={label} required={required}>{(p) => <Textarea {...p} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>
    </Modal>
  );
}
