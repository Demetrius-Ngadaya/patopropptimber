"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { ArrowLeft } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, today, toNumber } from "@/lib/format";
import { useAction } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { LineItemsEditor, type EditorLine } from "@/features/inventory/components/LineItemsEditor";
import { Button, Field, Input, PageHeader, Panel, Select, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function NewTransferPage() {
  const t = useT();
  const router = useRouter();
  const { branchId } = useAuth();
  const { branches = [] } = useLookups(["branches"]);
  const [lines, setLines] = useState<EditorLine[]>([]);
  const [h, setH] = useState({ to_branch_id: "", type: "free", due_date: "", notes: "" });
  const charged = h.type !== "free";
  const total = lines.reduce((s, l) => s + toNumber(l.quantity) * toNumber(l.unit_price), 0);
  const send = useAction(
    () => api.post<ApiResponse<{ id: number }>>("admin/transfers", { ...h, due_date: h.type === "credit" ? h.due_date || undefined : undefined, items: lines.map((l) => ({ product_id: l.product.id, quantity: toNumber(l.quantity), unit_price: charged ? toNumber(l.unit_price) : undefined })) }),
    { invalidate: [["admin/transfers"]], onSuccess: (r) => router.push(`/admin/transfers/${r.data.id}`) },
  );

  return (
    <Guard perm="transfers.create">
      <PageHeader back={<Link href="/admin/transfers" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Transfers")}</Link>} title={t("Send stock to another branch")} description={t("Expiry dates travel with the goods.")} />
      {branchId === "all" ? <p className="rounded-md bg-saffron-50 px-4 py-3 text-sm text-saffron-700">{t("Choose the sending branch from the branch menu at the top first.")}</p> : (
        <div className="grid gap-5 xl:grid-cols-[1fr_340px]">
          <Panel title={t("Products to send")}><LineItemsEditor lines={lines} onChange={setLines} showPrice={charged} priceLabel="Charge / unit" /></Panel>
          <Panel title={t("Where and how")}>
            <div className="space-y-4">
              <Field label={t("Send to")} required>{(p) => <Select {...p} value={h.to_branch_id} onChange={(e) => setH({ ...h, to_branch_id: e.target.value })}><option value="">{t("Choose branch…")}</option>{branches.filter((b) => String(b.id) !== branchId).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</Select>}</Field>
              <Field label={t("Type")}>{(p) => (
                <Select {...p} value={h.type} onChange={(e) => setH({ ...h, type: e.target.value })}>
                  <option value="free">{t("Free move (no charge)")}</option>
                  <option value="sale">{t("Sell to the branch (paid now)")}</option>
                  <option value="credit">{t("Sell on credit (branch pays later)")}</option>
                </Select>
              )}</Field>
              {h.type === "credit" && <Field label={t("Pay by")}>{(p) => <Input {...p} type="date" min={today()} value={h.due_date} onChange={(e) => setH({ ...h, due_date: e.target.value })} />}</Field>}
              {charged && <div className="flex justify-between rounded-md bg-ink-50 px-3 py-2 text-sm font-semibold"><span>{t("Total charged")}</span><span className="num">{money(total)}</span></div>}
              <Field label={t("Notes")}>{(p) => <Textarea {...p} rows={2} value={h.notes} onChange={(e) => setH({ ...h, notes: e.target.value })} />}</Field>
              <Button size="lg" className="w-full" loading={send.isPending} disabled={!lines.length || !h.to_branch_id} onClick={() => send.mutate(undefined)}>{t("Send stock")}</Button>
            </div>
          </Panel>
        </div>
      )}
    </Guard>
  );
}
