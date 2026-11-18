import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { create } from 'zustand';
import { api, ApiError, type Envelope } from '@/lib/api';
import { getMeta, setMeta } from '@/db/meta';

/** Shapes from the shop API (backend/app/Http/Controllers/Api/V1/Shop). */
export type ShopProduct = {
  id: number;
  name: string;
  slug: string;
  brand?: string | null;
  description?: string | null;
  description_sw?: string | null;
  selling_price: number | string;
  allow_decimal: boolean;
  image_url: string | null;
  unit?: string | null;
  in_stock: boolean;
  available_quantity?: number | null;
  category?: { id: number; name: string; slug: string } | null;
};
export type ShopCategory = { id: number; name: string; slug: string; products_count?: number };
export type ShopBranch = { id: number; name: string; location?: string | null; address?: string | null; phone?: string | null };
export type OrderStatus = 'pending' | 'confirmed' | 'processing' | 'ready' | 'out_for_delivery' | 'completed' | 'cancelled';
export type ShopOrder = {
  id: number;
  number: string;
  status: OrderStatus;
  delivery_method: 'pickup' | 'delivery';
  delivery_address?: string | null;
  contact_phone?: string | null;
  customer_notes?: string | null;
  subtotal?: number | string;
  delivery_fee?: number | string;
  total: number | string;
  items_count?: number;
  created_at: string;
  cancel_reason?: string | null;
  branch?: { id?: number; name: string; phone?: string | null; address?: string | null } | null;
  items?: { id: number; product_name: string; quantity: number | string; unit_price: number | string; line_total: number | string; product?: { slug: string; image_url?: string | null } | null }[];
  histories?: { id: number; to_status: OrderStatus; note?: string | null; created_at: string }[];
};

export const num = (v: number | string | null | undefined) => Number(v ?? 0) || 0;

/**
 * Data straight from the server for the shop screens (the shop needs internet).
 * `offline` tells "no connection" apart from other errors.
 */
export function useRemote<T>(load: (() => Promise<Envelope<T>>) | null, deps: unknown[]) {
  const [data, setData] = useState<T | undefined>(undefined);
  const [meta, setMetaInfo] = useState<Record<string, unknown> | undefined>(undefined);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<{ message: string; offline: boolean } | null>(null);
  const seq = useRef(0);
  const loadRef = useRef(load);
  useLayoutEffect(() => {
    loadRef.current = load;
  });

  const reload = useCallback(async () => {
    const fn = loadRef.current;
    if (!fn) return;
    const mine = ++seq.current;
    setLoading(true);
    try {
      const res = await fn();
      if (mine !== seq.current) return;
      setData(res.data);
      setMetaInfo(res.meta);
      setError(null);
    } catch (e) {
      if (mine !== seq.current) return;
      setError({ message: e instanceof ApiError ? e.firstError : String(e), offline: e instanceof ApiError && e.offline });
    } finally {
      if (mine === seq.current) setLoading(false);
    }
  }, []);

  useEffect(() => {
    reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- the caller lists what the request depends on
  }, deps);

  return { data, meta, loading, error, reload, setData };
}

export const shopApi = {
  products: (q: { search?: string; category?: string | null; page?: number; branch_id?: number | null }) =>
    api.get<ShopProduct[]>('shop/products', { per_page: 24, ...q }, { auth: false }),
  product: (slug: string, branchId?: number | null) => api.get<ShopProduct>(`shop/products/${encodeURIComponent(slug)}`, { branch_id: branchId }, { auth: false }),
  categories: () => api.get<ShopCategory[]>('shop/categories', undefined, { auth: false }),
  branches: () => api.get<ShopBranch[]>('shop/branches', undefined, { auth: false }),
  orders: (page = 1) => api.get<ShopOrder[]>('shop/account/orders', { page }),
  order: (number: string) => api.get<ShopOrder>(`shop/account/orders/${encodeURIComponent(number)}`),
};

// ------------------------------------------------------------------ basket

export type BasketItem = { product_id: number; slug: string; name: string; price: number; unit: string | null; image_url: string | null; allow_decimal: boolean; quantity: number };

type BasketState = {
  items: BasketItem[];
  branchId: number | null;
  loaded: boolean;
  load: () => Promise<void>;
  add: (p: ShopProduct, quantity?: number) => void;
  setQuantity: (productId: number, quantity: number) => void;
  remove: (productId: number) => void;
  clear: () => void;
  setBranch: (id: number | null) => void;
};

/** The basket lives on the phone (kept when the app closes). */
export const useBasket = create<BasketState>((set, get) => {
  const save = () => setMeta('shopBasket', { items: get().items, branchId: get().branchId }).catch(() => undefined);
  return {
    items: [],
    branchId: null,
    loaded: false,
    async load() {
      if (get().loaded) return;
      const saved = await getMeta<{ items: BasketItem[]; branchId: number | null }>('shopBasket', { items: [], branchId: null });
      set({ items: saved.items ?? [], branchId: saved.branchId ?? null, loaded: true });
    },
    add(p, quantity = 1) {
      const items = [...get().items];
      const i = items.findIndex((x) => x.product_id === p.id);
      if (i >= 0) items[i] = { ...items[i], quantity: items[i].quantity + quantity };
      else items.push({ product_id: p.id, slug: p.slug, name: p.name, price: num(p.selling_price), unit: p.unit ?? null, image_url: p.image_url, allow_decimal: p.allow_decimal, quantity });
      set({ items });
      save();
    },
    setQuantity(productId, quantity) {
      set({ items: quantity <= 0 ? get().items.filter((x) => x.product_id !== productId) : get().items.map((x) => (x.product_id === productId ? { ...x, quantity } : x)) });
      save();
    },
    remove(productId) {
      set({ items: get().items.filter((x) => x.product_id !== productId) });
      save();
    },
    clear() {
      set({ items: [] });
      save();
    },
    setBranch(id) {
      set({ branchId: id });
      save();
    },
  };
});

export const basketCount = (items: BasketItem[]) => items.reduce((n, i) => n + (i.allow_decimal ? 1 : i.quantity), 0);
export const basketTotal = (items: BasketItem[]) => items.reduce((n, i) => n + i.price * i.quantity, 0);

/** How a customer sees each order status. */
export const CUSTOMER_STATUS: Record<OrderStatus, { label: string; hint: string; tone: 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info'; icon: 'time-outline' | 'checkmark-circle' | 'cube' | 'bag-check' | 'bicycle' | 'checkmark-done' | 'close-circle' }> = {
  pending: { label: 'Received', hint: 'Waiting for the shop to confirm.', tone: 'warning', icon: 'time-outline' },
  confirmed: { label: 'Confirmed', hint: 'The shop has accepted your order.', tone: 'info', icon: 'checkmark-circle' },
  processing: { label: 'Being prepared', hint: 'Your products are being packed.', tone: 'primary', icon: 'cube' },
  ready: { label: 'Ready for collection', hint: 'Come and collect it at the branch.', tone: 'success', icon: 'bag-check' },
  out_for_delivery: { label: 'On the way', hint: 'Your order is on the way to you.', tone: 'primary', icon: 'bicycle' },
  completed: { label: 'Completed', hint: 'Thank you for shopping with us!', tone: 'success', icon: 'checkmark-done' },
  cancelled: { label: 'Cancelled', hint: 'This order was cancelled.', tone: 'danger', icon: 'close-circle' },
};
