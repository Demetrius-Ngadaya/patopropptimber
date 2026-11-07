"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { ArrowLeft } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, toNumber } from "@/lib/format";
import { useAction } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import { Guard } from "@/components/layout/Guard";
import { LineItemsEditor, type EditorLine } from "@/features/inventory/components/LineItemsEditor";
import { Button, Field, PageHeader, Panel, Select, Textarea } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function NewAllocationPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const { staff = [] } = useLookups(["staff"]);
  const [lines, setLines] = useState<EditorLine[]>([]);
  const [sellerId, setSellerId] = useState("");
  const [notes, setNotes] = useState("");
  const canPrice = can("pos.change_price");
  const total = lines.reduce((s, l) => s + toNumber(l.quantity) * toNumber(l.unit_price), 0);
  const issue = useAction(
    () => api.post<ApiResponse<{ id: number }>>("admin/allocations", { seller_id: Number(sellerId), notes: notes || undefined, items: lines.map((l) => ({ product_id: l.product.id, quantity: toNumber(l.quantity), unit_price: canPrice && toNumber(l.unit_price) !== toNumber(l.product.selling_price) ? toNumber(l.unit_price) : undefined })) }),
    { invalidate: [["admin/allocations"]], onSuccess: (r) => router.push(`/admin/allocations/${r.data.id}`) },
  );

  return (
    <Guard perm="allocations.create">
      <PageHeader back={<Link href="/admin/allocations" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Allocations")}</Link>} title={t("Issue stock to a seller")} description={t("The stock leaves this branch now. The seller is accountable for it until the allocation is reconciled.")} />
      <div className="grid gap-5 xl:grid-cols-[1fr_320px]">
        <Panel title={t("Products")}><LineItemsEditor lines={lines} onChange={setLines} showPrice={canPrice} priceLabel="Price to sell at" /></Panel>
        <Panel title={t("Seller")}>
          <div className="space-y-4">
            <Field label={t("Seller")} required>{(p) => <Select {...p} value={sellerId} onChange={(e) => setSellerId(e.target.value)}><option value="">{t("Choose a staff member…")}</option>{staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>}</Field>
            <div className="flex justify-between rounded-md bg-ink-50 px-3 py-2 text-sm font-semibold"><span>{t("Value to account for")}</span><span className="num">{money(total)}</span></div>
            <Field label={t("Notes")}>{(p) => <Textarea {...p} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
            <Button size="lg" className="w-full" loading={issue.isPending} disabled={!lines.length || !sellerId} onClick={() => issue.mutate(undefined)}>{t("Issue stock")}</Button>
          </div>
        </Panel>
      </div>
    </Guard>
  );
}
