"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { ArrowLeft, CheckCircle2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money, qty, toNumber } from "@/lib/format";
import { useAction, useApi } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Button, ConfirmDialog, DescriptionList, ErrorState, Field, Input, PageHeader, Panel, Spinner, StatusBadge, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Transfer = {
  id: number; number: string; type: string; status: string; payment_status: string; sent_at: string; received_at: string | null; due_date: string | null;
  total_cost: string; total_amount: string; receiving_costs: string; notes: string | null; receive_notes: string | null; cancel_reason: string | null;
  from_branch_id: number; to_branch_id: number; from_branch: { name: string }; to_branch: { name: string }; sender?: { name: string }; receiver?: { name: string } | null;
  items: Array<{ id: number; quantity: string; received_quantity: string | null; unit_cost?: string; unit_price: string; product: { name: string; unit?: { short_name: string } | null } }>;
};

export default function TransferDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const tr = useT();
  const { id } = use(params);
  const { can, branchId } = useAuth();
  const q = useApi<ApiResponse<Transfer>>(`admin/transfers/${id}`);
  const [received, setReceived] = useState<Record<number, string>>({});
  const [costs, setCosts] = useState("");
  const [notes, setNotes] = useState("");
  const [cancelling, setCancelling] = useState(false);
  const inv = [[`admin/transfers/${id}`], ["admin/transfers"], ["admin/dashboard"]];
  const receive = useAction(() => api.post(`admin/transfers/${id}/receive`, { items: Object.entries(received).map(([k, v]) => ({ transfer_item_id: Number(k), received_quantity: toNumber(v) })), receiving_costs: toNumber(costs), notes: notes || undefined }), { invalidate: inv });
  const cancel = useAction((reason: string) => api.post(`admin/transfers/${id}/cancel`, { reason }), { invalidate: inv, onSuccess: () => setCancelling(false) });
  const markPaid = useAction(() => api.post(`admin/transfers/${id}/mark-paid`), { invalidate: inv });
  const t = q.data?.data;

  useEffect(() => {
    if (t) setReceived(Object.fromEntries(t.items.map((i) => [i.id, String(toNumber(i.quantity))])));
  }, [t]);

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !t) return <ErrorState message={q.error?.message ?? tr("Transfer not found.")} />;
  const inTransit = t.status === "in_transit";
  const isReceiver = branchId === String(t.to_branch_id);
  const isSender = branchId === String(t.from_branch_id);

  return (
    <Guard perm="transfers.view">
      <PageHeader
        back={<Link href="/admin/transfers" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {tr("Transfers")}</Link>}
        title={tr("Transfer {number}", { number: t.number })}
        description={<span className="flex flex-wrap items-center gap-2">{t.from_branch.name} → {t.to_branch.name} <StatusBadge status={t.status} />{t.payment_status === "unpaid" && <StatusBadge status="unpaid" />}</span>}
        actions={
          <>
            {inTransit && isSender && can("transfers.cancel") && <Button variant="danger" onClick={() => setCancelling(true)}>{tr("Cancel transfer")}</Button>}
            {t.payment_status === "unpaid" && t.status !== "cancelled" && can("transfers.mark_paid") && <Button variant="secondary" loading={markPaid.isPending} onClick={() => markPaid.mutate(undefined)}>{tr("Mark as paid")}</Button>}
          </>
        }
      />
      {inTransit && !isReceiver && <p className="mb-4 rounded-md bg-sky-50 px-4 py-3 text-sm text-sky-800">{tr("Waiting for {name} to confirm they received the goods.", { name: t.to_branch.name })}</p>}
      {t.status === "cancelled" && <div className="mb-4"><ErrorState message={tr("Cancelled: {value}. Stock went back to {name}.", { value: t.cancel_reason ?? "", name: t.from_branch.name })} /></div>}
      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <Panel title={inTransit && isReceiver ? tr("Confirm what arrived") : tr("Items")} padded={false}>
          <table className="w-full text-sm">
            <thead><tr className="border-b border-ink-200 bg-ink-50/60 text-left text-[12.5px] text-ink-600"><th className="px-3 py-2">{tr("Product")}</th><th className="px-3 py-2 text-right">{tr("Sent")}</th><th className="px-3 py-2 text-right">{tr("Received")}</th>{t.type !== "free" && <th className="px-3 py-2 text-right">{tr("Charge / unit")}</th>}</tr></thead>
            <tbody>
              {t.items.map((i) => (
                <tr key={i.id} className="border-b border-ink-100">
                  <td className="px-3 py-2 font-medium">{i.product.name}</td>
                  <td className="num px-3 py-2 text-right">{qty(i.quantity, i.product.unit?.short_name)}</td>
                  <td className="px-3 py-2 text-right">
                    {inTransit && isReceiver && can("transfers.receive") ? (
                      <Input aria-label={tr("Received {name}", { name: i.product.name })} type="number" min={0} max={toNumber(i.quantity)} step="any" className="num ml-auto h-9 w-24 text-right" value={received[i.id] ?? ""} onChange={(e) => setReceived({ ...received, [i.id]: e.target.value })} />
                    ) : i.received_quantity !== null ? (
                      <span className={toNumber(i.received_quantity) < toNumber(i.quantity) ? "num font-semibold text-ember-600" : "num"}>{qty(i.received_quantity)}</span>
                    ) : "—"}
                  </td>
                  {t.type !== "free" && <td className="num px-3 py-2 text-right">{money(i.unit_price)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
          {inTransit && isReceiver && can("transfers.receive") && (
            <div className="grid gap-4 border-t border-ink-100 p-4 sm:grid-cols-[200px_1fr_auto] sm:items-end">
              <Field label={tr("Transport / handling paid")} hint={tr("Added to the cost of the goods.")}>{(p) => <Input {...p} type="number" min={0} step="any" value={costs} onChange={(e) => setCosts(e.target.value)} />}</Field>
              <Field label={tr("Notes (shortages, damage …)")}>{(p) => <Textarea {...p} rows={1} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
              <Button icon={<CheckCircle2 className="size-4" />} loading={receive.isPending} onClick={() => receive.mutate(undefined)}>{tr("Confirm received")}</Button>
            </div>
          )}
        </Panel>
        <Panel title={tr("Details")}>
          <DescriptionList items={[
            ["Type", t.type === "free" ? "Free move" : t.type === "sale" ? "Sold to branch" : "On credit"],
            ["Sent", `${dateTime(t.sent_at)}${t.sender ? `, ${t.sender.name}` : ""}`],
            ["Received", t.received_at ? `${dateTime(t.received_at)}${t.receiver ? `, ${t.receiver.name}` : ""}` : null],
            ["Value at cost", t.total_cost !== undefined ? money(t.total_cost) : null],
            ["Charged", t.type !== "free" ? money(t.total_amount) : null],
            ["Pay by", t.due_date ? date(t.due_date) : null],
            ["Receiving costs", toNumber(t.receiving_costs) ? money(t.receiving_costs) : null],
            ["Notes", t.notes],
            ["Receiving notes", t.receive_notes],
          ]} />
        </Panel>
      </div>
      <ConfirmDialog open={cancelling} onClose={() => setCancelling(false)} onConfirm={(r) => cancel.mutate(r)} loading={cancel.isPending} title={tr("Cancel this transfer?")} message={tr("All items go back into stock at {name}.", { name: t.from_branch.name })} confirmLabel={tr("Cancel transfer")} requireReason />
    </Guard>
  );
}
