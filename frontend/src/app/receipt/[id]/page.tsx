"use client";

import { use, useEffect } from "react";
import { Printer } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import { dateTime, money, qty, toNumber } from "@/lib/format";
import { paymentMethodLabels } from "@/lib/lookups";
import type { Sale } from "@/lib/types";
import { Barcode } from "@/features/catalog/components/Barcode";
import { Button, ErrorState, SkeletonReceipt } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { BRAND } from "@/lib/brand";

const widths = { "58mm": "48mm", "80mm": "72mm", a4: "180mm" } as const;

export default function ReceiptPage({ params }: { params: Promise<{ id: string }> }) {
  const tr = useT();
  const { id } = use(params);
  const sale = useApi<ApiResponse<Sale>>(`admin/sales/${id}`);
  const { settings } = usePublicSettings();
  const paper = settings?.receipt_width ?? "80mm";
  const s = sale.data?.data;

  useEffect(() => {
    if (s && settings) {
      const t = setTimeout(() => window.print(), 400);
      return () => clearTimeout(t);
    }
  }, [s, settings]);

  if (sale.isLoading || !settings) return <SkeletonReceipt />;
  if (sale.error || !s) return <div className="p-6"><ErrorState message={sale.error?.message ?? tr("Receipt not found.")} /></div>;

  const line = "border-t border-dashed border-black/60 my-1.5";
  // Settings → Documents decides which company details a receipt carries.
  const chosen = settings.document_company_fields?.receipt;
  const shows = (field: string) => (chosen ? chosen.includes(field) : ["logo", "address", "phone", "tin"].includes(field));
  const buyerTin = s.customer?.tin as string | undefined;
  const buyerVrn = s.customer?.vrn as string | undefined;

  return (
    <div className="min-h-dvh bg-ink-100 py-6 print:bg-surface print:py-0">
      <style>{`@page { size: ${paper === "a4" ? "A4" : `${paper} auto`}; margin: ${paper === "a4" ? "12mm" : "2mm"}; }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-md justify-center gap-2">
        <Button icon={<Printer className="size-4" />} onClick={() => window.print()}>{tr("Print again")}</Button>
        <Button variant="secondary" onClick={() => window.close()}>{tr("Close")}</Button>
      </div>

      <article style={{ width: widths[paper] }} className="keep-light mx-auto bg-white p-3 font-mono text-[11.5px] leading-[1.35] text-black shadow print:p-0 print:shadow-none">
        <header className="text-center">
          {settings.receipt_show_logo && shows("logo") && settings.business_logo_url && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={settings.business_logo_url} alt="" className="mx-auto mb-1 size-16 rounded-full object-cover grayscale" />
          )}
          <p className="text-[14px] font-bold uppercase">{settings.business_name}</p>
          {s.branch?.name && <p>{s.branch.name}</p>}
          {shows("address") && settings.business_address && <p>{settings.business_address}{settings.business_po_box ? `, ${settings.business_po_box}` : ""}</p>}
          {shows("phone") && settings.business_phone && <p>{tr("Tel: {business_phone}", { business_phone: settings.business_phone })}</p>}
          {shows("whatsapp") && settings.business_whatsapp && <p>WhatsApp: {settings.business_whatsapp}</p>}
          {shows("email") && settings.business_email && <p>{settings.business_email}</p>}
          {shows("website") && settings.business_website && <p>{settings.business_website}</p>}
          {shows("tin") && settings.business_tin && <p>{tr("TIN: {business_tin}", { business_tin: settings.business_tin })}</p>}
          {shows("vrn") && settings.business_vrn && <p>{tr("VRN: {business_vrn}", { business_vrn: settings.business_vrn })}</p>}
        </header>

        <div className={line} />
        <div className="flex justify-between"><span>{tr("Receipt")}</span><span>{s.number}</span></div>
        <div className="flex justify-between"><span>{tr("Date")}</span><span>{dateTime(s.sold_at)}</span></div>
        <div className="flex justify-between"><span>{tr("Cashier")}</span><span>{s.cashier?.name}</span></div>
        {(s.customer?.name || s.customer_name) && <div className="flex justify-between"><span>{tr("Customer")}</span><span className="text-right">{s.customer?.name ?? s.customer_name}</span></div>}
        {buyerTin && <div className="flex justify-between"><span>{tr("Customer TIN")}</span><span>{buyerTin}</span></div>}
        {buyerVrn && <div className="flex justify-between"><span>{tr("Customer VRN")}</span><span>{buyerVrn}</span></div>}
        {s.status === "cancelled" && <p className="my-1 text-center font-bold">{tr("*** CANCELLED ***")}</p>}
        <div className={line} />

        <table className="w-full">
          <tbody>
            {s.items?.map((i) => (
              <tr key={i.id} className="align-top">
                <td className="pb-1">
                  <div>{i.product_name}</div>
                  <div className="text-[10.5px]">
                    {qty(i.quantity, i.product?.unit?.short_name)} x {money(i.unit_price)}
                    {toNumber(i.discount) > 0 && ` (-${money(i.discount)})`}
                  </div>
                </td>
                <td className="pb-1 text-right">{money(i.line_total)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className={line} />
        {toNumber(s.discount) > 0 && <div className="flex justify-between"><span>{tr("Discount")}</span><span>-{money(s.discount)}</span></div>}
        {toNumber(s.delivery_fee) > 0 && <div className="flex justify-between"><span>{tr("Delivery")}</span><span>{money(s.delivery_fee)}</span></div>}
        {toNumber(s.tax) > 0 && <div className="flex justify-between"><span>{tr("VAT incl.")}</span><span>{money(s.tax)}</span></div>}
        <div className="flex justify-between text-[14px] font-bold"><span>{tr("TOTAL {currency}", { currency: settings.currency })}</span><span>{money(s.total)}</span></div>
        {s.payments?.filter((p) => toNumber(p.amount) > 0).map((p) => (
          <div key={p.id} className="flex justify-between">
            <span>{tr(paymentMethodLabels[p.method] ?? p.method)}{p.provider ? ` ${p.provider}` : ""}{p.reference ? ` #${p.reference}` : ""}</span>
            <span>{money(p.amount)}</span>
          </div>
        ))}
        {toNumber(s.change_given) > 0 && <div className="flex justify-between"><span>{tr("Change")}</span><span>{money(s.change_given)}</span></div>}
        {toNumber(s.balance) > 0 && (
          <div className="flex justify-between font-bold"><span>{tr("Balance due")} {s.due_date ? tr("by {toLocaleDateString}", { toLocaleDateString: new Date(s.due_date).toLocaleDateString("en-GB") }) : ""}</span><span>{money(s.balance)}</span></div>
        )}
        {s.notes && <p className="mt-1">{s.notes}</p>}

        <div className={line} />
        <div className="flex justify-center py-1">
          <Barcode value={s.number} height={32} width={1.1} fontSize={10} />
        </div>
        {shows("bank") && settings.business_bank_details && <p className="mb-1 whitespace-pre-line text-center text-[10.5px]">{settings.business_bank_details}</p>}
        <p className="text-center">{settings.receipt_footer}</p>
        <p className="mt-1.5 text-center text-[9.5px]">{BRAND.poweredBy}</p>
      </article>
    </div>
  );
}
