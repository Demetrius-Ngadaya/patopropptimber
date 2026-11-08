"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useState } from "react";
import { ArrowLeft, Barcode as BarcodeIcon, ExternalLink, Pencil, Printer, QrCode as QrIcon, SlidersHorizontal, Trash2 } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money, qty, toNumber, humanize } from "@/lib/format";
import { useAction, useApi, useUrlParam } from "@/lib/hooks";
import type { Product } from "@/lib/types";
import { Guard, Can } from "@/components/layout/Guard";
import { ProductForm } from "@/features/catalog/components/ProductForm";
import { AdjustStockModal } from "@/features/inventory/components/AdjustStockModal";
import { Barcode } from "@/features/catalog/components/Barcode";
import { QrCode } from "@/features/catalog/components/QrCode";
import { usePublicSettings } from "@/lib/public-settings";
import { ProductUnitsPanel } from "@/features/catalog/components/ProductUnitsPanel";
import { AiDescriptionPanel } from "@/features/catalog/components/AiDescriptionPanel";
import { BranchPricesPanel, type BranchPrice } from "@/features/catalog/components/BranchPricesPanel";
import { Badge, Button, ConfirmDialog, DataTable, DescriptionList, ErrorState, PageHeader, Panel, Spinner, Stat, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Detail = {
  product: Product & { creator?: { name: string } | null; updater?: { name: string } | null; created_at: string; updated_at: string };
  stocks: Array<{ id: number; quantity: string; branch: { name: string } }>;
  branch_prices: BranchPrice[];
  total_stock: number;
  qr: string | null;
  batches: Array<{ id: number; batch_no: string | null; expiry_date: string | null; quantity_remaining: string; unit_cost?: string; branch: { name: string } }>;
  movements: Array<{ id: number; type: string; quantity: string; balance_after: string; note: string | null; created_at: string; user?: { name: string } | null; branch: { name: string } }>;
};
type AuditEntry = { id: number; event: string; description: string; user_name: string | null; created_at: string; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null };

export default function ProductDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const t = useT();
  const { id } = use(params);
  const router = useRouter();
  const { can } = useAuth();
  const q = useApi<ApiResponse<Detail>>(`admin/products/${id}`);
  const [tab, setTab] = useState<"batches" | "movements" | "history">("movements");
  const history = useApi<ApiResponse<AuditEntry[]>>(tab === "history" && can("audit.changes") ? `admin/audit/changes/product/${id}` : null);
  const [editing, setEditing] = useState(false);
  const [adjusting, setAdjusting] = useState(false);
  useUrlParam("adjust", () => can("stock.adjust") && setAdjusting(true)); // from a scanned QR code
  const [deleting, setDeleting] = useState(false);
  const remove = useAction((reason: string) => api.del(`admin/products/${id}`, { reason }), { invalidate: [["admin/products"]], onSuccess: () => router.replace("/admin/products") });
  const generate = useAction(() => api.post("admin/products/barcodes", { ids: [Number(id)] }), { invalidate: [[`admin/products/${id}`], ["admin/products"]] });
  const { settings } = usePublicSettings();
  const barcodes = settings?.barcode_enabled !== false;

  if (q.isLoading) return <Spinner variant="page" />;
  if (q.error || !q.data) return <ErrorState message={q.error?.message ?? t("Product not found.")} />;
  const { product: p, stocks, total_stock, batches, movements, qr, branch_prices = [] } = q.data.data;
  const unit = p.unit?.short_name;
  // What the stock is worth, using each branch's own prices where it has them.
  const costValue = branch_prices.reduce((a, b) => a + b.quantity * (b.effective.cost_price ?? 0), 0);
  const retailValue = branch_prices.reduce((a, b) => a + b.quantity * (b.effective.selling_price ?? 0), 0);

  return (
    <Guard perm="products.view">
      <PageHeader
        back={<Link href="/admin/products" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Products")}</Link>}
        title={p.name}
        description={<span className="flex flex-wrap gap-2">{p.category?.name}{p.track_expiry && <Badge tone="blue">{t("Tracks expiry")}</Badge>}{p.allow_decimal && <Badge>{t("Sold by")} {unit ?? "weight"}</Badge>}{p.show_online && <Badge tone="green">{t("In online shop")}</Badge>}{!p.is_active && <Badge tone="red">{t("Inactive")}</Badge>}{p.deleted_at && <Badge tone="red">{t("Deleted")}</Badge>}</span>}
        actions={!p.deleted_at && (
          <>
            <Can perm="stock.adjust"><Button variant="secondary" icon={<SlidersHorizontal className="size-4" />} onClick={() => setAdjusting(true)}>{t("Adjust stock")}</Button></Can>
            <Can perm="products.print_labels"><Button variant="secondary" icon={<Printer className="size-4" />} onClick={() => router.push(`/admin/products/labels?ids=${p.id}&type=${barcodes && p.barcode ? (qr ? "both" : "barcode") : "qr"}`)}>{t("Print labels")}</Button></Can>
            <Can perm="products.update"><Button variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>{t("Edit")}</Button></Can>
            <Can perm="products.delete"><Button variant="ghost" className="text-ember-600" aria-label={t("Delete product")} onClick={() => setDeleting(true)}><Trash2 className="size-4" /></Button></Can>
          </>
        )}
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3">
        <Stat label={t("In stock (selected branches)")} value={qty(total_stock, unit)} tone={total_stock <= 0 ? "ember" : total_stock <= toNumber(p.reorder_level) ? "saffron" : "default"} sub={`Warn at ${qty(p.reorder_level, unit)}`} />
        <Stat label={t("Selling price")} value={money(p.selling_price)} sub={p.min_selling_price ? `Lowest allowed ${money(p.min_selling_price)}` : undefined} />
        {p.cost_price !== undefined && <Stat label={t("Buying price (landed)")} value={money(p.cost_price)} sub={`Margin ${money(toNumber(p.selling_price) - toNumber(p.cost_price))}`} />}
        {p.cost_price !== undefined && <Stat label={t("Stock value at buying price")} value={money(costValue)} />}
        <Stat label={t("Money after selling the stock")} value={money(retailValue)} tone="brand" sub={t("At each branch's selling price")} />
        {p.cost_price !== undefined && <Stat label={t("Expected profit")} value={money(retailValue - costValue)} tone="saffron" />}
      </div>
      {branch_prices.length > 0 && <div className="mb-5"><BranchPricesPanel productId={p.id} unit={unit} rows={branch_prices} /></div>}
      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <Panel padded={false}>
          <div className="px-3 pt-2"><Tabs value={tab} onChange={setTab} tabs={[{ value: "movements", label: t("Stock movements") }, { value: "batches", label: t("Batches ({count})", { count: batches.length }) }, ...(can("audit.changes") ? [{ value: "history" as const, label: t("Change history") }] : [])]} /></div>
          {tab === "movements" && (
            <DataTable rows={movements} rowKey={(r) => r.id} empty={{ title: t("No stock movements yet") }} columns={[
              { key: "created_at", header: t("Date"), render: (r) => dateTime(r.created_at) },
              { key: "type", header: t("Type"), render: (r) => humanize(r.type) },
              { key: "branch.name", header: t("Branch"), hideOnMobile: true },
              { key: "quantity", header: t("Change"), align: "right", render: (r) => <span className={toNumber(r.quantity) < 0 ? "text-ember-600" : "text-brand-700"}>{toNumber(r.quantity) > 0 ? "+" : ""}{qty(r.quantity)}</span> },
              { key: "balance_after", header: t("Balance"), align: "right", render: (r) => qty(r.balance_after) },
              { key: "note", header: t("Reference"), hideOnMobile: true },
              { key: "user.name", header: t("By"), hideOnMobile: true },
            ]} />
          )}
          {tab === "batches" && (
            <DataTable rows={batches} rowKey={(r) => r.id} empty={{ title: t("No batches with stock") }} columns={[
              { key: "batch_no", header: t("Batch") },
              { key: "branch.name", header: t("Branch") },
              { key: "expiry_date", header: t("Expiry"), render: (r) => r.expiry_date ? <span className={new Date(r.expiry_date) < new Date() ? "font-semibold text-ember-600" : undefined}>{date(r.expiry_date)}</span> : "—" },
              { key: "quantity_remaining", header: t("Qty"), align: "right", render: (r) => qty(r.quantity_remaining, unit) },
              ...(p.cost_price !== undefined ? [{ key: "unit_cost", header: t("Unit cost"), align: "right" as const, render: (r: Detail["batches"][number]) => money(r.unit_cost) }] : []),
            ]} />
          )}
          {tab === "history" && (
            <ul className="divide-y divide-ink-100">
              {(history.data?.data ?? []).map((h) => (
                <li key={h.id} className="px-4 py-3 text-sm">
                  <p className="font-medium">{h.description}</p>
                  <p className="text-xs text-ink-500">{dateTime(h.created_at)}, {h.user_name ?? "system"}</p>
                  {h.event === "updated" && h.new_values && (
                    <ul className="mt-1 space-y-0.5 text-[13px]">
                      {Object.entries(h.new_values).map(([k, v]) => (
                        <li key={k}><span className="text-ink-500">{humanize(k)}:</span> <span className="text-ember-600 line-through">{String(h.old_values?.[k] ?? "—")}</span> → <span className="text-brand-700">{String(v ?? "—")}</span></li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Panel>
        <div className="space-y-5">
          <Panel title={t("Details")}>
            {(barcodes || qr) && (
              <div className="keep-light mb-4 flex flex-wrap items-center justify-center gap-4 rounded-md bg-white p-3 ring-1 ring-ink-100">
                {barcodes && (p.barcode
                  ? <Barcode value={p.barcode} height={44} />
                  : <Can perm="products.update"><Button size="sm" variant="secondary" icon={<BarcodeIcon className="size-4" />} loading={generate.isPending} onClick={() => generate.mutate(undefined)}>{t("Generate barcode")}</Button></Can>)}
                {qr && <QrCode value={qr} size={84} />}
              </div>
            )}
            {qr && qr.startsWith("http") && <a href={qr} target="_blank" rel="noopener noreferrer" className="mb-3 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline"><QrIcon className="size-3.5" />{t("Open what the QR code shows")}<ExternalLink className="size-3" /></a>}
            <DescriptionList items={[["SKU", p.sku], ["Brand", p.brand], ["Unit", p.unit ? `${p.unit.name} (${p.unit.short_name})` : null], ["Tax", `${toNumber(p.tax_rate)}%`], ["Added", `${date(p.created_at)}${p.creator ? `, ${p.creator.name}` : ""}`], ["Last change", `${date(p.updated_at)}${p.updater ? `, ${p.updater.name}` : ""}`]]} />
            {p.description && <p className="mt-3 text-sm text-ink-700">{p.description}</p>}
            {p.description_sw && <p lang="sw" className="mt-2 text-sm italic text-ink-600">{p.description_sw}</p>}
          </Panel>
          <ProductUnitsPanel product={p} />
          {can("ai.generate") && <AiDescriptionPanel product={p} />}
          <Panel title={t("Stock by branch")}>
            <ul className="space-y-2 text-sm">
              {stocks.map((s) => <li key={s.id} className="flex justify-between"><span>{s.branch.name}</span><span className="num font-medium">{qty(s.quantity, unit)}</span></li>)}
              {!stocks.length && <li className="text-ink-500">{t("No stock recorded yet.")}</li>}
            </ul>
          </Panel>
        </div>
      </div>
      <ProductForm open={editing} onClose={() => setEditing(false)} product={p} />
      {adjusting && <AdjustStockModal open={adjusting} onClose={() => setAdjusting(false)} product={p} onDone={() => q.refetch()} />}
      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} onConfirm={(reason) => remove.mutate(reason)} loading={remove.isPending} title={t("Delete {name}?", { name: p.name })} message={t("The product must have no stock. It is hidden everywhere and can be restored later. Sales history is kept.")} confirmLabel={t("Delete product")} requireReason />
    </Guard>
  );
}
