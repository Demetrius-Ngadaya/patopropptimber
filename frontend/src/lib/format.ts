// Numbers always use 12,500.50 grouping (familiar on both English and Swahili receipts);
// dates follow the user's language (set by the i18n provider in the browser).
const moneyFormatter = new Intl.NumberFormat("en-US", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const qtyFormatter = new Intl.NumberFormat("en-US", { maximumFractionDigits: 3 });
let dateLocale = "en-GB";

export function setFormatLocale(locale: string) {
  dateLocale = locale;
}

export function toNumber(value: unknown): number {
  const n = typeof value === "number" ? value : parseFloat(String(value ?? 0));
  return Number.isFinite(n) ? n : 0;
}

/** 12,500 or 12,500.50 (currency shown separately to keep tables clean). */
export function money(value: unknown, currency?: string): string {
  const s = moneyFormatter.format(toNumber(value));
  return currency ? `${currency} ${s}` : s;
}

export function qty(value: unknown, unit?: string | null): string {
  const s = qtyFormatter.format(toNumber(value));
  return unit ? `${s} ${unit}` : s;
}

/** "2026-01-10" is a calendar date, not UTC midnight: read it in local time so it never shifts a day. */
function parseDate(value: string): Date {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : new Date(value);
}

export function date(value?: string | null): string {
  if (!value) return "—";
  const d = parseDate(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString(dateLocale, { day: "2-digit", month: "short", year: "numeric" });
}

export function dateTime(value?: string | null): string {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString(dateLocale, { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function today(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function monthStart(): string {
  return today().slice(0, 8) + "01";
}

export function humanize(value?: string | null): string {
  if (!value) return "";
  const s = value.replace(/[_.]/g, " ");
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export function cn(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(" ");
}

/** Any Intl date format in the user's language, e.g. formatDate(d, { month: "long", year: "numeric" }). */
export function formatDate(value: Date | string, options: Intl.DateTimeFormatOptions): string {
  const d = typeof value === "string" ? parseDate(value) : value;
  return Number.isNaN(d.getTime()) ? String(value) : options.hour || options.minute ? d.toLocaleTimeString(dateLocale, options) : d.toLocaleDateString(dateLocale, options);
}

/** "2026-09" -> "September 2026" / "Septemba 2026". */
export function monthLabel(period: string): string {
  return formatDate(`${period}-01`, { month: "long", year: "numeric" });
}
