"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

export type ShopProduct = {
  id: number;
  name: string;
  slug: string;
  brand?: string | null;
  description?: string | null;
  selling_price: number | string;
  allow_decimal: boolean;
  image_url: string | null;
  unit?: string | null;
  in_stock: boolean;
  available_quantity?: number | null;
  category?: { id: number; name: string; slug: string } | null;
};
export type CartItem = { product_id: number; name: string; slug: string; price: number; unit: string | null; image_url: string | null; allow_decimal: boolean; quantity: number };

type CartContextValue = {
  items: CartItem[];
  count: number;
  subtotal: number;
  branchId: number | null;
  setBranchId: (id: number | null) => void;
  add: (p: ShopProduct, quantity?: number) => void;
  setQuantity: (productId: number, quantity: number) => void;
  remove: (productId: number) => void;
  clear: () => void;
};

const KEY = "mz_cart_v1";
const CartContext = createContext<CartContextValue | null>(null);

function read(): { items: CartItem[]; branchId: number | null } {
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return { items: [], branchId: null };
    const parsed = JSON.parse(raw);
    const items = Array.isArray(parsed.items) ? parsed.items.filter((i: CartItem) => i && Number.isFinite(i.product_id) && i.quantity > 0) : [];
    return { items, branchId: typeof parsed.branchId === "number" ? parsed.branchId : null };
  } catch {
    return { items: [], branchId: null };
  }
}

/** Shopping cart kept in the browser; prices are re-checked by the server when the order is placed. */
export function CartProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState<CartItem[]>([]);
  const [branchId, setBranchId] = useState<number | null>(null);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    const saved = read();
    setItems(saved.items);
    setBranchId(saved.branchId);
    setLoaded(true);
  }, []);

  useEffect(() => {
    if (!loaded) return;
    try {
      window.localStorage.setItem(KEY, JSON.stringify({ items, branchId }));
    } catch {
      /* private mode: cart lives for this tab only */
    }
  }, [items, branchId, loaded]);

  const add = useCallback((p: ShopProduct, quantity = 1) => {
    setItems((list) => {
      const existing = list.find((i) => i.product_id === p.id);
      if (existing) return list.map((i) => (i.product_id === p.id ? { ...i, quantity: round(i.quantity + quantity) } : i));
      return [...list, { product_id: p.id, name: p.name, slug: p.slug, price: Number(p.selling_price), unit: p.unit ?? null, image_url: p.image_url, allow_decimal: p.allow_decimal, quantity: round(quantity) }];
    });
  }, []);
  const setQuantity = useCallback((productId: number, quantity: number) => {
    setItems((list) => (quantity <= 0 ? list.filter((i) => i.product_id !== productId) : list.map((i) => (i.product_id === productId ? { ...i, quantity: round(quantity) } : i))));
  }, []);
  const remove = useCallback((productId: number) => setItems((list) => list.filter((i) => i.product_id !== productId)), []);
  const clear = useCallback(() => setItems([]), []);

  const value = useMemo(() => ({
    items,
    count: items.length,
    subtotal: Math.round(items.reduce((s, i) => s + i.price * i.quantity, 0) * 100) / 100,
    branchId,
    setBranchId,
    add,
    setQuantity,
    remove,
    clear,
  }), [items, branchId, add, setQuantity, remove, clear]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

function round(n: number) {
  return Math.round(n * 1000) / 1000;
}

export function useCart(): CartContextValue {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart must be used inside <CartProvider>");
  return ctx;
}
