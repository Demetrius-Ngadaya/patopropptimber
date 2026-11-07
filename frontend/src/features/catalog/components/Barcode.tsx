"use client";

import { useEffect, useRef } from "react";
import JsBarcode from "jsbarcode";
import { useT } from "@/lib/i18n";

export function Barcode({ value, height = 40, width = 1.4, fontSize = 12, displayValue = true, format }: { value: string; height?: number; width?: number; fontSize?: number; displayValue?: boolean; format?: string }) {
  const t = useT();
  const ref = useRef<SVGSVGElement>(null);
  useEffect(() => {
    if (!ref.current || !value) return;
    const isEan = /^\d{13}$/.test(value);
    try {
      JsBarcode(ref.current, value, { format: format ?? (isEan ? "EAN13" : "CODE128"), height, width, fontSize, margin: 0, displayValue });
    } catch {
      JsBarcode(ref.current, value, { format: "CODE128", height, width, fontSize, margin: 0, displayValue });
    }
  }, [value, height, width, fontSize, displayValue, format]);
  return <svg ref={ref} role="img" aria-label={t("Barcode {value}", { value: value })} />;
}
