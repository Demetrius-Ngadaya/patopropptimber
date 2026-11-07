"use client";

import { Link2, Type } from "lucide-react";
import { cn } from "@/lib/format";
import { useT } from "@/lib/i18n";

const FIELD_LABELS: Record<string, string> = {
  name: "Product name",
  price: "Selling price",
  wholesale_price: "Wholesale price",
  description: "Description",
  category: "Category",
  brand: "Brand",
  unit: "Unit",
  sku: "SKU",
  barcode: "Barcode",
  image: "Picture",
  availability: "In stock or not",
  business: "Business name and phone",
};

/** Settings → Barcodes & QR codes: what a QR code holds and what customers see. */
export function LabelsEditor({ mode, fields, allFields, onChange, disabled, qrEnabled }: {
  mode: "link" | "text";
  fields: string[];
  allFields: string[];
  onChange: (key: "qr_mode" | "qr_public_fields", value: unknown) => void;
  disabled?: boolean;
  qrEnabled: boolean;
}) {
  const t = useT();
  const toggle = (f: string) => onChange("qr_public_fields", fields.includes(f) ? fields.filter((x) => x !== f) : [...fields, f]);
  const options = [
    { value: "link" as const, icon: Link2, title: t("A link (recommended)"), text: t("Scanning opens the product page. Customers see the details ticked below; logged-in staff see stock and sales according to their role. Prices stay current without reprinting.") },
    { value: "text" as const, icon: Type, title: t("The details as text"), text: t("The ticked details are written into the code itself. Works without internet, but staff see no stock or sales and labels must be reprinted when prices change.") },
  ];

  return (
    <div className={cn("space-y-6", !qrEnabled && "pointer-events-none opacity-50")} aria-disabled={!qrEnabled}>
      <section>
        <h3 className="mb-2 text-sm font-semibold text-ink-900">{t("What the QR code holds")}</h3>
        <div className="grid gap-2 md:grid-cols-2">
          {options.map((o) => (
            <label key={o.value} className={cn("flex cursor-pointer gap-3 rounded-md p-3 ring-1", mode === o.value ? "bg-brand-50 ring-brand-500" : "ring-ink-200 hover:bg-ink-50")}>
              <input type="radio" name="qr_mode" className="mt-1 size-4 accent-brand-600" checked={mode === o.value} disabled={disabled} onChange={() => onChange("qr_mode", o.value)} />
              <span>
                <span className="flex items-center gap-1.5 text-sm font-medium text-ink-900"><o.icon className="size-4" />{o.title}</span>
                <span className="mt-0.5 block text-xs leading-relaxed text-ink-600">{o.text}</span>
              </span>
            </label>
          ))}
        </div>
      </section>
      <section>
        <h3 className="text-sm font-semibold text-ink-900">{t("What customers see when they scan")}</h3>
        <p className="mb-3 text-sm text-ink-600">{t("Buying prices, stock numbers and sales are never shown to customers.")}</p>
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {allFields.map((f) => (
            <label key={f} className="flex cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 ring-1 ring-ink-100 hover:bg-ink-50">
              <input type="checkbox" className="size-4 accent-brand-600" checked={fields.includes(f)} disabled={disabled} onChange={() => toggle(f)} />
              <span className="text-sm text-ink-800">{t(FIELD_LABELS[f] ?? f)}</span>
            </label>
          ))}
        </div>
      </section>
    </div>
  );
}
