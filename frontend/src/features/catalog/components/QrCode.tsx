"use client";

import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { useT } from "@/lib/i18n";

/** A QR code drawn as SVG (sharp at any print size). */
export function QrCode({ value, size, className }: { value: string; size: number | string; className?: string }) {
  const t = useT();
  const [svg, setSvg] = useState("");
  useEffect(() => {
    let alive = true;
    QRCode.toString(value, { type: "svg", margin: 0, errorCorrectionLevel: value.length > 300 ? "L" : "M" })
      .then((s) => alive && setSvg(s))
      .catch(() => alive && setSvg(""));
    return () => {
      alive = false;
    };
  }, [value]);
  const dim = typeof size === "number" ? `${size}px` : size;
  return (
    <span
      role="img"
      aria-label={t("QR code")}
      className={className}
      style={{ display: "inline-block", width: dim, height: dim, lineHeight: 0 }}
      // SVG generated locally by the qrcode library from the product's own data.
      dangerouslySetInnerHTML={{ __html: svg.replace("<svg ", '<svg width="100%" height="100%" ') }}
    />
  );
}
