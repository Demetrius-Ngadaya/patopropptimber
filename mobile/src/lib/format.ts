/** Money, numbers and dates the way Tanzanian shops read them. */

let currency = 'TZS';

export function setCurrency(code: string | undefined | null) {
  if (code) currency = code;
}

export function currencyCode(): string {
  return currency;
}

const grouped = (n: number, decimals = 0) =>
  n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

/** 12,500 → "TZS 12,500". Cents only when there are any. */
export function money(amount: number | null | undefined, withCode = true): string {
  const n = Number(amount ?? 0);
  const decimals = Math.abs(n % 1) > 0.004 ? 2 : 0;
  const text = grouped(n, decimals);
  return withCode ? `${currency} ${text}` : text;
}

export function qty(n: number | null | undefined): string {
  const v = Number(n ?? 0);
  return Number.isInteger(v) ? grouped(v) : grouped(v, v * 100 === Math.round(v * 100) ? 2 : 3);
}

export function round2(n: number): number {
  return Math.round((n + Number.EPSILON) * 100) / 100;
}

const pad = (n: number) => String(n).padStart(2, '0');

export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function time(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** "just now", "5 min ago", "3 h ago", or the date. Words come from the caller's translator. */
export function ago(iso: string | null | undefined, t: (k: string, p?: Record<string, string | number>) => string, now = Date.now()): string {
  if (!iso) return t('never');
  const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));
  if (seconds < 45) return t('just now');
  if (seconds < 3600) return t('{n} min ago', { n: Math.round(seconds / 60) });
  if (seconds < 86400) return t('{n} h ago', { n: Math.round(seconds / 3600) });
  if (seconds < 7 * 86400) return t('{n} days ago', { n: Math.round(seconds / 86400) });
  return dateTime(iso).slice(0, 10);
}

export function isToday(iso: string | null | undefined, now = new Date()): boolean {
  if (!iso) return false;
  const d = new Date(iso);
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
}

/** Tanzanian numbers to the international form used by WhatsApp and tel: links. */
export function phoneIntl(phone: string | null | undefined): string | null {
  if (!phone) return null;
  const digits = phone.replace(/[^0-9+]/g, '');
  if (digits.startsWith('+')) return digits.slice(1);
  if (digits.startsWith('0')) return `255${digits.slice(1)}`;
  return digits;
}

/** Keep only what a number box may hold. */
export function cleanNumber(raw: string, decimals: boolean): string {
  let s = raw.replace(/,/g, '.').replace(decimals ? /[^0-9.]/g : /[^0-9]/g, '');
  if (decimals) {
    const i = s.indexOf('.');
    if (i >= 0) s = s.slice(0, i + 1) + s.slice(i + 1).replace(/\./g, '');
  }
  return s.replace(/^0+(?=\d)/, '');
}
