"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Barcode as BarcodeIcon, Download, FileSpreadsheet, Plus, Printer, QrCode, RotateCcw, Upload, X } from "lucide-react";
import { toast } from "sonner";
import { api, download, ApiError, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money, qty, toNumber } from "@/lib/format";
import { useAction, useApi, useDebounced } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import type { Product } from "@/lib/types";
import { Guard, Can } from "@/components/layout/Guard";
import { ProductForm } from "@/features/catalog/components/ProductForm";
import { Badge, Button, Checkbox, DataTable, Modal, PageHeader, Pagination, Panel, SearchInput, Select, Stat } from "@/components/ui";

type ProductTotals = { quantity: number; retail_value: number; cost_value?: number; expected_profit?: number };
import { useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { saveLabelSelection, selectionBody, type LabelSelection } from "@/features/catalog/labelSelection";

type ImportResult = { created: number; updated: number; failed: Array<{ row: number; errors: string[] }> };

function ImportModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);
  const run = useAction(() => { const fd = new FormData(); fd.append("file", file!); return api.post<ApiResponse<ImportResult>>("admin/products/import", fd); }, { invalidate: [["admin/products"]], onSuccess: (r) => setResult(r.data) });
  return (
    <Modal open={open} onClose={() => { setResult(null); setFile(null); onClose(); }} title={t("Import products from Excel")} description={t("Products with an existing SKU or barcode are updated; new ones are created with their opening stock in this branch.")} size="md"
      footer={<><Button variant="secondary" icon={<Download className="size-4" />} onClick={() => download("admin/products/import/template").catch((e: ApiError) => toast.error(e.message))}>{t("Download template")}</Button><Button icon={<Upload className="size-4" />} loading={run.isPending} disabled={!file} onClick={() => run.mutate(undefined)}>{t("Import")}</Button></>}>
      <div className="space-y-4">
        <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-ink-200 px-4 py-8 text-center hover:border-brand-500">
          <FileSpreadsheet className="size-8 text-ink-400" aria-hidden />
          <span className="text-sm font-medium">{file ? file.name : t("Choose an .xlsx or .csv file")}</span>
          <span className="text-xs text-ink-500">{t("Use the template so the columns match.")}</span>
          <input type="file" accept=".xlsx,.xls,.csv" className="sr-only" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setResult(null); }} />
        </label>
        {result && (
          <div className="space-y-2 text-sm">
            <p><strong>{result.created}</strong> {t("created,")} <strong>{result.updated}</strong> {t("updated,")} <strong className={result.failed.length ? "text-ember-600" : ""}>{result.failed.length}</strong> {t("failed.")}</p>
            {result.failed.length > 0 && (
              <ul className="max-h-48 space-y-1 overflow-y-auto rounded-md bg-ember-50 p-3 text-ember-700">
                {result.failed.map((f) => <li key={f.row}>{t("Row {row}: {join}", { row: f.row, join: f.errors.join(" ") })}</li>)}
              </ul>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

function GenerateBarcodesModal({ open, onClose, selection, count }: { open: boolean; onClose: () => void; selection: LabelSelection; count: number }) {
  const t = useT();
  const [overwrite, setOverwrite] = useState(false);
  const generate = useAction(() => api.post("admin/products/barcodes", { ...selectionBody(selection), overwrite }), {
    invalidate: [["admin/products"]],
    onSuccess: () => { setOverwrite(false); onClose(); },
  });
  return (
    <Modal open={open} onClose={onClose} title={t("Generate barcodes")} size="md"
      description={t("{count} products selected. Products that already have a barcode keep it.", { count })}
      footer={<><Button variant="ghost" onClick={onClose}>{t("Cancel")}</Button><Button icon={<BarcodeIcon className="size-4" />} loading={generate.isPending} onClick={() => generate.mutate(undefined)}>{t("Generate")}</Button></>}>
      <div className="space-y-3 text-sm text-ink-700">
        <p>{t("New barcodes are EAN-13 numbers starting with 20, the range kept for shops' own codes, so they never clash with the codes printed by manufacturers.")}</p>
        <Checkbox label={t("Also replace barcodes that products already have")} checked={overwrite} onChange={(e) => setOverwrite(e.target.checked)} />
        {overwrite && <p className="rounded-md bg-ember-50 px-3 py-2 text-ember-700">{t("Manufacturer barcodes on the packaging will no longer scan for these products. Only do this if you will stick new labels on them.")}</p>}
      </div>
    </Modal>
  );
}

export default function ProductsPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const { categories = [] } = useLookups(["categories"]);
  const [filters, setFilters] = useState({ search: "", category_id: "", stock: "", active: "" });
  const [trashed, setTrashed] = useState(false);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number[]>([]);
  const [allMatching, setAllMatching] = useState(false);
  const [generating, setGenerating] = useState(false);
  const { settings } = usePublicSettings();
  const barcodes = settings?.barcode_enabled !== false;
  const qrCodes = settings?.qr_enabled !== false;
  const [creating, setCreating] = useState(false);
  const [importing, setImporting] = useState(false);
  const search = useDebounced(filters.search);
  const list = useApi<Paginated<Product> & { summary?: ProductTotals }>("admin/products", { ...filters, search, page, trashed: trashed ? "only" : undefined });
  const sum = list.data?.summary;
  const onePage = (list.data?.meta?.last_page ?? 1) <= 1;
  const restore = useAction((id: number) => api.post(`admin/products/${id}/restore`), { invalidate: [["admin/products"]] });
  const set = (k: keyof typeof filters, v: string) => { setFilters((f) => ({ ...f, [k]: v })); setPage(1); setAllMatching(false); };
  const rows = list.data?.data ?? [];
  const toggle = (id: number) => { setAllMatching(false); setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id])); };
  const total = list.data?.meta?.total ?? 0;
  const pageAllSelected = rows.length > 0 && rows.every((r) => selected.includes(r.id));
  const togglePage = (on: boolean) => { setAllMatching(false); setSelected((s) => (on ? [...new Set([...s, ...rows.map((r) => r.id)])] : s.filter((id) => !rows.some((r) => r.id === id)))); };
  const count = allMatching ? total : selected.length;
  const selection: LabelSelection = allMatching ? { all: true, search: filters.search, category_id: filters.category_id, active: filters.active } : { ids: selected };
  const clear = () => { setSelected([]); setAllMatching(false); };
  const print = (type: "barcode" | "qr") => { saveLabelSelection(selection); router.push(`/admin/products/labels?type=${type}&sel=1`); };
  const canSelect = can(["products.print_labels", "products.update"]) && !trashed;

  return (
    <Guard perm="products.view">
      <PageHeader
        title={t("Products")}
        description={t("Stock shown is for the selected branch.")}
        actions={
          <>
            <Can perm="products.import"><Button variant="secondary" icon={<Upload className="size-4" />} onClick={() => setImporting(true)}>{t("Import")}</Button></Can>
            <Can perm="products.create"><Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t("New product")}</Button></Can>
          </>
        }
      />
      {sum && !trashed && (
        <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Stat label={t("Items in stock")} value={qty(sum.quantity)} />
          {sum.cost_value !== undefined && <Stat label={t("Stock value at buying price")} value={money(sum.cost_value)} />}
          <Stat label={t("Money after selling the stock")} value={money(sum.retail_value)} tone="brand" />
          {sum.expected_profit !== undefined && <Stat label={t("Expected profit")} value={money(sum.expected_profit)} tone="saffron" />}
        </div>
      )}
      <Panel padded={false}>
        <div className="grid gap-3 border-b border-ink-100 p-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto]">
          <SearchInput value={filters.search} onChange={(v) => set("search", v)} placeholder={t("Name, SKU, barcode or brand")} />
          <Select aria-label={t("Category")} value={filters.category_id} onChange={(e) => set("category_id", e.target.value)}><option value="">{t("All categories")}</option>{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select>
          <Select aria-label={t("Stock")} value={filters.stock} onChange={(e) => set("stock", e.target.value)}><option value="">{t("Any stock level")}</option><option value="low">{t("Low stock")}</option><option value="out">{t("Out of stock")}</option></Select>
          <Select aria-label={t("Status")} value={filters.active} onChange={(e) => set("active", e.target.value)}><option value="">{t("Active and inactive")}</option><option value="1">{t("Active")}</option><option value="0">{t("Inactive")}</option></Select>
          {can("products.restore") && <Checkbox label={t("Deleted")} checked={trashed} onChange={(e) => setTrashed(e.target.checked)} className="self-center" />}
        </div>
        {count > 0 && (
          <div className="flex flex-wrap items-center gap-2 border-b border-ink-100 bg-brand-50/60 px-3 py-2">
            <span className="text-sm font-medium text-ink-900">{t("{count} selected", { count })}</span>
            {!allMatching && pageAllSelected && total > selected.length && (
              <button type="button" className="text-sm font-medium text-brand-700 hover:underline" onClick={() => setAllMatching(true)}>{t("Select all {total} matching products", { total })}</button>
            )}
            <span className="flex-1" />
            {barcodes && <Can perm="products.update"><Button size="sm" variant="secondary" icon={<BarcodeIcon className="size-4" />} onClick={() => setGenerating(true)}>{t("Generate barcodes")}</Button></Can>}
            {barcodes && <Can perm="products.print_labels"><Button size="sm" variant="secondary" icon={<Printer className="size-4" />} onClick={() => print("barcode")}>{t("Print barcodes")}</Button></Can>}
            {qrCodes && <Can perm="products.print_labels"><Button size="sm" variant="secondary" icon={<QrCode className="size-4" />} onClick={() => print("qr")}>{t("Print QR codes")}</Button></Can>}
            <Button size="sm" variant="ghost" icon={<X className="size-4" />} onClick={clear}>{t("Clear")}</Button>
          </div>
        )}
        <DataTable
          rows={rows}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={trashed ? undefined : (r) => router.push(`/admin/products/${r.id}`)}
          columns={[
            ...(canSelect ? [{
              key: "_sel", header: <input type="checkbox" aria-label={t("Select all on this page")} className="size-4 accent-brand-600" checked={allMatching || pageAllSelected} onChange={(e) => togglePage(e.target.checked)} />,
              render: (r: Product) => <input type="checkbox" aria-label={t("Select {name}", { name: r.name })} className="size-4 accent-brand-600" checked={allMatching || selected.includes(r.id)} onClick={(e) => e.stopPropagation()} onChange={() => (allMatching ? (setAllMatching(false), setSelected(rows.map((x) => x.id).filter((x) => x !== r.id))) : toggle(r.id))} />,
            }] : []),
            { key: "name", header: t("Product"), render: (r) => (
              <div className="flex items-center gap-3">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                {r.image_url ? <img src={r.image_url} alt="" className="size-9 rounded-md object-cover ring-1 ring-ink-200" /> : <span className="grid size-9 place-items-center rounded-md bg-ink-100 text-xs font-semibold text-ink-500">{r.name.slice(0, 2).toUpperCase()}</span>}
                <span><span className="font-medium text-ink-900">{r.name}</span><span className="block text-xs text-ink-500">{[r.sku, r.barcode].filter(Boolean).join("  |  ")}</span></span>
              </div>
            ) },
            { key: "category.name", header: t("Category"), hideOnMobile: true },
            ...(can("products.view_cost") ? [{ key: "cost_price", header: t("Buying price"), align: "right" as const, hideOnMobile: true, total: false as const, render: (r: Product) => money(r.cost_price) }] : []),
            { key: "selling_price", header: t("Selling price"), align: "right", total: false, render: (r) => <span title={r.branch_price ? t("This branch's own price") : undefined}>{money(r.selling_price)}{r.branch_price && <span className="ml-1 text-[10px] font-semibold text-brand-700">●</span>}</span> },
            { key: "stock_quantity", header: t("In stock"), align: "right", total: "number", totalValue: onePage ? undefined : sum?.quantity, render: (r) => {
              if (r.is_service) return <span className="text-ink-400">{t("service")}</span>;
              const s = toNumber(r.stock_quantity);
              return <span className={s <= 0 ? "font-semibold text-ember-600" : s <= toNumber(r.reorder_level) ? "font-semibold text-saffron-700" : undefined}>{qty(s, r.unit?.short_name)}</span>;
            } },
            ...(can("products.view_cost") ? [{ key: "stock_cost_value", header: t("Value at buying price"), align: "right" as const, hideOnMobile: true, total: "money" as const, totalValue: onePage ? undefined : sum?.cost_value, render: (r: Product) => money(r.stock_cost_value) }] : []),
            { key: "stock_retail_value", header: t("Value at selling price"), align: "right", total: "money", totalValue: onePage ? undefined : sum?.retail_value, render: (r) => money(r.stock_retail_value) },
            { key: "flags", header: "", hideOnMobile: true, render: (r) => <span className="flex gap-1">{r.track_expiry && <Badge tone="blue">{t("Expiry")}</Badge>}{r.show_online && <Badge tone="green">{t("Online")}</Badge>}{!r.is_active && <Badge tone="red">{t("Inactive")}</Badge>}</span> },
            ...(trashed ? [{ key: "_r", header: "", align: "right" as const, render: (r: Product) => <Button size="sm" variant="secondary" icon={<RotateCcw className="size-3.5" />} onClick={() => restore.mutate(r.id)}>{t("Restore")}</Button> }] : []),
          ]}
          empty={{ title: trashed ? t("No deleted products") : t("No products found"), message: trashed ? undefined : t("Add products one by one or import them from Excel."), action: !trashed && can("products.create") ? <Button onClick={() => setCreating(true)}>{t("Add a product")}</Button> : undefined }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      <ProductForm open={creating} onClose={() => setCreating(false)} onSaved={(p) => router.push(`/admin/products/${p.id}`)} />
      <ImportModal open={importing} onClose={() => setImporting(false)} />
      <GenerateBarcodesModal open={generating} onClose={() => setGenerating(false)} selection={selection} count={count} />
    </Guard>
  );
}
