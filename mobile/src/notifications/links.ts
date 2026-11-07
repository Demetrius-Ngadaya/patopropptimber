/**
 * Where a notification opens. The server sends web paths (/admin/orders/12);
 * the app opens the matching screen, or the notification itself.
 */
export type NotificationData = {
  notification_id?: number | string;
  type?: string;
  category?: string;
  url?: string | null;
  title?: string;
  body?: string | null;
  speak?: boolean;
  sound?: boolean;
  vibrate?: boolean;
  channel_id?: string;
  display?: boolean;
  local?: boolean;
  spoken?: boolean;
  level?: string;
  created_at?: string;
};

export function routeFor(data: NotificationData): string {
  const url = data.url ?? '';
  const id = Number(data.notification_id ?? 0);
  const path = url.split('?')[0];
  const m = (re: RegExp) => path.match(re)?.[1];

  const order = m(/^\/admin\/orders\/(\d+)/);
  if (order) return `/orders/${order}`;
  const product = m(/^\/admin\/products\/(\d+)/);
  if (product) return `/products/${product}`;
  const sale = m(/^\/admin\/sales\/(\d+)/);
  if (sale) return `/sales/s:${sale}`;
  const customer = m(/^\/admin\/customers\/(\d+)/);
  if (customer) return `/customers/s:${customer}`;
  if (path.startsWith('/admin/orders')) return '/orders';
  if (path.startsWith('/admin/stock') || data.category === 'stock') return id ? `/notifications/${id}` : '/products?filter=low';
  return id ? `/notifications/${id}` : '/notifications';
}

/** Expo puts the payload in different places depending on platform and app state. */
export function extractData(raw: unknown): NotificationData {
  if (!raw || typeof raw !== 'object') return {};
  const r = raw as Record<string, unknown>;
  for (const key of ['dataString', 'body']) {
    if (typeof r[key] === 'string') {
      try {
        const parsed = JSON.parse(r[key] as string);
        if (parsed && typeof parsed === 'object') return parsed as NotificationData;
      } catch {
        // not JSON
      }
    }
  }
  if (r.data && typeof r.data === 'object') return extractData(r.data);
  return r as NotificationData;
}
