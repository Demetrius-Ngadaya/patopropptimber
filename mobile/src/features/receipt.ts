import * as Print from 'expo-print';
import * as Sharing from 'expo-sharing';
import { Linking } from 'react-native';
import { dateTime, money, phoneIntl, qty } from '@/lib/format';
import type { BusinessSettings, Sale } from '@/lib/types';

/** Receipts made on the phone, so they work offline: PDF to share or print, or WhatsApp text. */
const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]!);

const METHOD: Record<string, string> = { cash: 'Cash', mobile_money: 'Mobile money', bank: 'Bank', card: 'Card', cheque: 'Cheque' };

export function receiptHtml(sale: Sale, settings: BusinessSettings, t: (k: string, p?: Record<string, string | number>) => string): string {
  const number = sale.number ?? sale.local_number ?? '';
  const rows = sale.items
    .map((i) => `<tr><td>${esc(i.name)}<br><small>${qty(i.quantity)} × ${money(i.unit_price, false)}${i.unit ? ` / ${esc(i.unit)}` : ''}</small></td><td class="r">${money(i.line_total, false)}</td></tr>`)
    .join('');
  const pays = sale.payments.map((p) => `<tr><td>${esc(t(METHOD[p.method] ?? p.method))}${p.reference ? ` <small>${esc(p.reference)}</small>` : ''}</td><td class="r">${money(p.amount, false)}</td></tr>`).join('');
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
  <style>
    body{font-family:-apple-system,Roboto,Helvetica,Arial,sans-serif;color:#0E1726;width:300px;margin:0 auto;padding:16px;font-size:13px}
    h1{font-size:18px;margin:0;text-align:center} .c{text-align:center} .m{color:#5B6B82} small{color:#5B6B82}
    table{width:100%;border-collapse:collapse;margin-top:10px} td{padding:5px 0;vertical-align:top} .r{text-align:right;white-space:nowrap}
    .t td{border-top:1px dashed #9AA9C0;font-weight:700;font-size:15px;padding-top:8px} hr{border:0;border-top:1px dashed #9AA9C0;margin:12px 0}
  </style></head><body>
  <h1>${esc(settings.business_name ?? 'PatoPro')}</h1>
  <div class="c m">${esc(settings.business_address ?? '')}${settings.business_phone ? `<br>${esc(settings.business_phone)}` : ''}</div>
  <hr><div>${esc(t('Receipt'))} <b>${esc(number)}</b><br><span class="m">${dateTime(sale.sold_at)}</span>${sale.customer_name ? `<br>${esc(t('Customer'))}: ${esc(sale.customer_name)}` : ''}</div>
  <table>${rows}<tr class="t"><td>${esc(t('Total'))}</td><td class="r">${money(sale.total)}</td></tr></table>
  <table>${pays}${sale.change_given > 0 ? `<tr><td>${esc(t('Change'))}</td><td class="r">${money(sale.change_given, false)}</td></tr>` : ''}${sale.balance > 0 ? `<tr><td><b>${esc(t('Balance (credit)'))}</b></td><td class="r"><b>${money(sale.balance, false)}</b></td></tr>` : ''}</table>
  <hr><div class="c">${esc(settings.receipt_footer ?? t('Thank you for your business!'))}</div>
  <div class="c m" style="margin-top:10px;font-size:10px">Powered by SHEZONI PatoPro</div>
  </body></html>`;
}

export async function shareReceiptPdf(sale: Sale, settings: BusinessSettings, t: (k: string, p?: Record<string, string | number>) => string) {
  const { uri } = await Print.printToFileAsync({ html: receiptHtml(sale, settings, t), width: 320, height: 640 + sale.items.length * 40 });
  if (await Sharing.isAvailableAsync()) {
    await Sharing.shareAsync(uri, { mimeType: 'application/pdf', dialogTitle: t('Share receipt'), UTI: 'com.adobe.pdf' });
  }
}

export async function printReceipt(sale: Sale, settings: BusinessSettings, t: (k: string, p?: Record<string, string | number>) => string) {
  await Print.printAsync({ html: receiptHtml(sale, settings, t) });
}

export function receiptText(sale: Sale, settings: BusinessSettings, t: (k: string, p?: Record<string, string | number>) => string): string {
  const lines = sale.items.map((i) => `• ${qty(i.quantity)} × ${i.name} = ${money(i.line_total, false)}`).join('\n');
  return [
    `*${settings.business_name ?? 'PatoPro'}*`,
    `${t('Receipt')} ${sale.number ?? sale.local_number} · ${dateTime(sale.sold_at)}`,
    '',
    lines,
    '',
    `*${t('Total')}: ${money(sale.total)}*`,
    sale.balance > 0 ? `${t('Balance (credit)')}: ${money(sale.balance)}` : null,
    '',
    settings.receipt_footer ?? t('Thank you for your business!'),
  ]
    .filter((l) => l !== null)
    .join('\n');
}

export async function sendWhatsApp(text: string, phone?: string | null) {
  const to = phoneIntl(phone);
  const url = `whatsapp://send?${to ? `phone=${to}&` : ''}text=${encodeURIComponent(text)}`;
  const web = `https://wa.me/${to ?? ''}?text=${encodeURIComponent(text)}`;
  try {
    await Linking.openURL(url);
  } catch {
    await Linking.openURL(web);
  }
}
