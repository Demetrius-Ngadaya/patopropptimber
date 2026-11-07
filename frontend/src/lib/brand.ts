/**
 * The product brand (SHEZONI PatoPro). Not the business using it: the
 * business name and logo come from Settings → Business (usePublicSettings).
 * Change the colours here to SHEZONI's exact brand values if they differ.
 */
export const BRAND = {
  product: "PatoPro",
  company: "SHEZONI",
  companyLegal: "SHEZONI Technologies Ltd",
  version: "1.0",
  poweredBy: "Powered by SHEZONI PatoPro",
  /** SHEZONI deep blue: the "Pato" in the wordmark and the app icon. */
  blue: "#0B3D91",
  /** A lighter blue for "Pato" on dark backgrounds. */
  blueOnDark: "#7FA8F5",
  /** Green accent: money and growth. */
  green: "#16A34A",
  greenOnDark: "#4ADE80",
} as const;

/** "© 2026 SHEZONI Technologies Ltd · PatoPro v1.0" */
export function copyrightLine(year = new Date().getFullYear()): string {
  return `© ${year} ${BRAND.companyLegal} · ${BRAND.product} v${BRAND.version}`;
}

/** The browser tab title: "PatoPro — Dashboard". */
export function tabTitle(page?: string): string {
  return page ? `${BRAND.product} — ${page}` : BRAND.product;
}
