import { create } from 'zustand';
import { levelFor, quote, type PriceLevel } from './pricing';
import { round2 } from '../../lib/format';
import type { Customer, Product, ProductUnit, SaleLine } from '../../lib/types';

/** The till's cart. Prices are re-quoted whenever quantity, unit or customer changes. */
export type CartLine = {
  key: string;
  product: Product;
  unit: ProductUnit | null;
  quantity: number;
};

type CartState = {
  lines: CartLine[];
  customer: Customer | null;
  chosenLevel: PriceLevel | null;
  discount: number;
  autoWholesale: boolean;
  add: (product: Product, unit?: ProductUnit | null, quantity?: number) => void;
  setQuantity: (key: string, quantity: number) => void;
  remove: (key: string) => void;
  setCustomer: (c: Customer | null) => void;
  setLevel: (l: PriceLevel | null) => void;
  setDiscount: (n: number) => void;
  setAutoWholesale: (v: boolean) => void;
  clear: () => void;
};

const keyOf = (product: Product, unit: ProductUnit | null) => `${product.id}:${unit?.id ?? 0}`;

export const useCart = create<CartState>((set) => ({
  lines: [],
  customer: null,
  chosenLevel: null,
  discount: 0,
  autoWholesale: true,
  add: (product, unit = null, quantity = 1) =>
    set((s) => {
      const key = keyOf(product, unit);
      const existing = s.lines.find((l) => l.key === key);
      if (existing) return { lines: s.lines.map((l) => (l.key === key ? { ...l, quantity: l.quantity + quantity } : l)) };
      // Newest on top, where the cashier is looking.
      return { lines: [{ key, product, unit, quantity }, ...s.lines] };
    }),
  setQuantity: (key, quantity) =>
    set((s) => ({ lines: quantity <= 0 ? s.lines.filter((l) => l.key !== key) : s.lines.map((l) => (l.key === key ? { ...l, quantity } : l)) })),
  remove: (key) => set((s) => ({ lines: s.lines.filter((l) => l.key !== key) })),
  setCustomer: (customer) => set({ customer }),
  setLevel: (chosenLevel) => set({ chosenLevel }),
  setDiscount: (discount) => set({ discount: Math.max(0, discount) }),
  setAutoWholesale: (autoWholesale) => set({ autoWholesale }),
  clear: () => set({ lines: [], customer: null, chosenLevel: null, discount: 0 }),
}));

export type PricedLine = CartLine & { unitPrice: number; lineTotal: number; level: PriceLevel; baseQuantity: number; overStock: boolean };

export function priceLines(state: Pick<CartState, 'lines' | 'customer' | 'chosenLevel' | 'autoWholesale'>): PricedLine[] {
  const level = levelFor(state.customer?.customer_type, state.chosenLevel);
  // Stock is checked per product across all its units (2 cartons + 5 pieces).
  const used = new Map<number, number>();
  return state.lines.map((l) => {
    const q = quote(l.product, l.unit, l.quantity, level, state.autoWholesale);
    const total = (used.get(l.product.id) ?? 0) + q.baseQuantity;
    used.set(l.product.id, total);
    return {
      ...l,
      unitPrice: q.price,
      lineTotal: round2(q.price * l.quantity),
      level: q.level,
      baseQuantity: q.baseQuantity,
      overStock: !l.product.is_service && total > l.product.stock + 0.0005,
    };
  });
}

export function toSaleLines(lines: PricedLine[]): SaleLine[] {
  return lines.map((l) => ({
    product_id: l.product.id,
    product_unit_id: l.unit?.id ?? null,
    name: l.product.name,
    unit: l.unit?.name ?? l.product.unit,
    quantity: l.quantity,
    base_quantity: l.baseQuantity,
    unit_price: l.unitPrice,
    line_total: l.lineTotal,
  }));
}
