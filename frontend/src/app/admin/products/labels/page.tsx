"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { ArrowLeft, Barcode as BarcodeIcon, Printer } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { money } from "@/lib/format";
import { useAction } from "@/lib/hooks";
import { Can, Guard } from "@/components/layout/Guard";
import { Barcode } from "@/features/catalog/components/Barcode";
import { QrCode } from "@/features/catalog/components/QrCode";
import { readLabelSelection, selectionBody, type LabelSelection } from "@/features/catalog/labelSelection";
import { Button, Checkbox, EmptyState, ErrorState, Field, Input, PageHeader, Panel, Select, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Label = { id: number; name: string; sku: string | null; barcode: string | null; price: number; wholesale_price: number | null; unit: string | null; qr: string | null };
type Payload = { barcode_enabled: boolean; qr_enabled: boolean; business: string; currency: string; labels: Label[] };
type Kind = "barcode" | "qr" | "both";

/**
 * Paper layouts. A4 / Letter sheets are filled 4 across (the usual sticker
 * sheet); label rolls print one label per page at the label's exact size;
 * receipt printers print a continuous strip.
 */
const FORMATS = {
  "a4-4": { label: "A4 sheet, 4 across", page: "A4", margin: "8mm", columns: 4, width: "auto", sheet: true },
  "letter-4": { label: "Letter sheet, 4 across", page: "letter", margin: "8mm", columns: 4, width: "auto", sheet: true },
  "a4-3": { label: "A4 sheet, 3 across (bigger)", page: "A4", margin: "8mm", columns: 3, width: "auto", sheet: true },
  "roll-38x25": { label: "Label roll 38 × 25 mm", page: "38mm 25mm", margin: "0", columns: 1, width: "38mm", height: "25mm", sheet: false },
  "roll-50x30": { label: "Label roll 50 × 30 mm", page: "50mm 30mm", margin: "0", columns: 1, width: "50mm", height: "30mm", sheet: false },
  "roll-60x40": { label: "Label roll 60 × 40 mm", page: "60mm 40mm", margin: "0", columns: 1, width: "60mm", height: "40mm", sheet: false },
  "thermal-58": { label: "Receipt printer 58 mm", page: "58mm auto", margin: "2mm", columns: 1, width: "54mm", sheet: false },
  "thermal-80": { label: "Receipt printer 80 mm", page: "80mm auto", margin: "3mm", columns: 2, width: "74mm", sheet: false },
} as const;
type FormatKey = keyof typeof FORMATS;

export default function LabelsPage() {
  const t = useT();
  const [selection, setSelection] = useState<LabelSelection | null>(null);
  const [data, setData] = useState<Payload | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [kind, setKind] = useState<Kind>("barcode");
  const [format, setFormat] = useState<FormatKey>("a4-4");
  const [copies, setCopies] = useState(1);
  const [show, setShow] = useState({ name: true, price: true, business: false });

  // The selection comes from the product list (session storage) or ?ids= (a single product).
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const type = params.get("type");
    if (type === "qr" || type === "both" || type === "barcode") setKind(type);
    const ids = (params.get("ids") ?? "").split(",").map(Number).filter(Boolean);
    setSelection(params.get("sel") === "1" ? readLabelSelection() ?? { ids } : { ids });
  }, []);

  const load = (sel: LabelSelection) => api.post<ApiResponse<Payload>>("admin/products/labels", selectionBody(sel))
    .then((r) => { setData(r.data); setError(null); })
    .catch((e: Error) => setError(e.message));

  useEffect(() => {
    if (selection) load(selection);
  }, [selection]);

  // Barcodes for the products that have none yet, without leaving the page.
  const missing = data?.labels.filter((l) => !l.barcode) ?? [];
  const generate = useAction(() => api.post("admin/products/barcodes", { ids: missing.map((l) => l.id) }), { onSuccess: () => selection && load(selection) });

  const effectiveKind: Kind = !data ? kind : !data.qr_enabled ? "barcode" : !data.barcode_enabled ? "qr" : kind;
  const needsBarcode = effectiveKind !== "qr";
  const printable = useMemo(
    () => (data?.labels ?? []).filter((l) => (needsBarcode ? Boolean(l.barcode) : Boolean(l.qr))).flatMap((l) => Array.from({ length: copies }, (_, i) => ({ ...l, key: `${l.id}-${i}` }))),
    [data, copies, needsBarcode],
  );
  const f = FORMATS[format];
  const roll = "height" in f;
  const labelHeight = roll ? f.height : effectiveKind === "barcode" ? "30mm" : effectiveKind === "qr" ? "46mm" : "38mm";

  return (
    <Guard perm="products.print_labels">
      {/* Page size and margins for the chosen paper; the app chrome is hidden when printing. */}
      <style>{`@media print { @page { size: ${f.page}; margin: ${f.margin}; } main { padding: 0 !important; max-width: none !important; } }`}</style>
      <div className="no-print">
        <PageHeader
          back={<Link href="/admin/products" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {t("Products")}</Link>}
          title={effectiveKind === "qr" ? t("QR code labels") : t("Barcode labels")}
          description={data ? t("{products} products, {labels} labels.", { products: data.labels.length, labels: printable.length }) : undefined}
          actions={<Button icon={<Printer className="size-4" />} onClick={() => window.print()} disabled={!printable.length}>{t("Print {count} labels", { count: printable.length })}</Button>}
        />
        <Panel className="mb-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field label={t("Code")}>{(p) => (
              <Select {...p} value={effectiveKind} onChange={(e) => setKind(e.target.value as Kind)}>
                {data?.barcode_enabled !== false && <option value="barcode">{t("Barcode")}</option>}
                {data?.qr_enabled !== false && <option value="qr">{t("QR code")}</option>}
                {data?.barcode_enabled !== false && data?.qr_enabled !== false && <option value="both">{t("Barcode and QR code")}</option>}
              </Select>
            )}</Field>
            <Field label={t("Paper")}>{(p) => (
              <Select {...p} value={format} onChange={(e) => setFormat(e.target.value as FormatKey)}>
                {(Object.keys(FORMATS) as FormatKey[]).map((k) => <option key={k} value={k}>{t(FORMATS[k].label)}</option>)}
              </Select>
            )}</Field>
            <Field label={t("Copies of each")}>{(p) => <Input {...p} type="number" min={1} max={100} value={copies} onChange={(e) => setCopies(Math.max(1, Math.min(100, Number(e.target.value) || 1)))} />}</Field>
            <div className="flex flex-col gap-2 pt-1">
              <Checkbox label={t("Product name")} checked={show.name} onChange={(e) => setShow({ ...show, name: e.target.checked })} />
              <Checkbox label={t("Price")} checked={show.price} onChange={(e) => setShow({ ...show, price: e.target.checked })} />
              <Checkbox label={t("Business name")} checked={show.business} onChange={(e) => setShow({ ...show, business: e.target.checked })} />
            </div>
          </div>
          {needsBarcode && missing.length > 0 && (
            <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-700">
              <span>{t("{count} products have no barcode and are left out.", { count: missing.length })}</span>
              <Can perm="products.update"><Button size="sm" variant="secondary" icon={<BarcodeIcon className="size-4" />} loading={generate.isPending} onClick={() => generate.mutate(undefined)}>{t("Generate them now")}</Button></Can>
            </div>
          )}
          <p className="mt-3 text-xs text-ink-500">{f.sheet ? t("In the print window choose the paper size above, scale 100% and no headers or footers.") : t("Set the printer's paper to the label size, scale 100% and no margins.")}</p>
        </Panel>
      </div>

      {error ? <ErrorState message={error} /> : !data ? <Spinner /> : printable.length === 0 ? (
        <EmptyState title={t("Nothing to print")} message={needsBarcode ? t("None of the selected products has a barcode yet.") : t("QR codes are switched off in Settings.")} />
      ) : (
        <div
          className="keep-light grid gap-[2mm] bg-white print:gap-[1.5mm]"
          style={roll
            ? { gridTemplateColumns: f.width, width: "max-content" }
            : { gridTemplateColumns: `repeat(${f.columns}, minmax(0, 1fr))`, width: f.sheet ? undefined : f.width }}
        >
          {printable.map((l) => (
            <div
              key={l.key}
              className="flex flex-col items-center justify-center overflow-hidden rounded border border-dashed border-ink-200 bg-white p-[1.5mm] text-center text-black print:rounded-none print:border-transparent"
              style={{ height: labelHeight, width: roll ? f.width : undefined, breakInside: "avoid", pageBreakAfter: roll ? "always" : undefined }}
            >
              {show.business && <p className="w-full truncate text-[7px] uppercase tracking-wide text-neutral-600">{data.business}</p>}
              {show.name && <p className="w-full truncate text-[9px] font-semibold leading-tight">{l.name}</p>}
              {/* 3 mm between the codes: the quiet zone a barcode needs to scan reliably. */}
              <div className="flex min-h-0 flex-1 items-center justify-center gap-[3mm]">
                {effectiveKind !== "qr" && l.barcode && <Barcode value={l.barcode} height={roll && format === "roll-38x25" ? 22 : effectiveKind === "both" ? 30 : 34} width={effectiveKind === "both" || format === "roll-38x25" ? 1 : 1.25} fontSize={9} />}
                {effectiveKind !== "barcode" && l.qr && <QrCode value={l.qr} size={effectiveKind === "both" ? "16mm" : roll ? `calc(${f.height} - 9mm)` : "28mm"} />}
              </div>
              {show.price && <p className="num text-[10px] font-bold leading-tight">{money(l.price)}{l.unit ? ` / ${l.unit}` : ""}</p>}
            </div>
          ))}
        </div>
      )}
    </Guard>
  );
}
