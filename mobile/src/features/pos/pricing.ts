/**
 * Offline price check, mirroring backend/app/Services/Catalog/PricingService:
 *  - retail by default; wholesale for wholesale customers (or when the
 *    cashier chooses it);
 *  - retail turns into wholesale automatically once the quantity reaches
 *    the product's wholesale minimum (when the business allows it);
 *  - a pack unit (e.g. carton of 12) has its own prices, else the base
 *    price × the pack size;
 *  - no wholesale price → retail price.
 * The server prices the sale again when it syncs; this is what the cashier
 * and the customer see at the till.
 */
export type PriceLevel = 'retail' | 'wholesale';

export type PricedProduct = {
  selling_price: number;
  wholesale_price: number | null;
  wholesale_min_qty: number | null;
};

export type PricedUnit = { factor: number; retail_price: number; wholesale_price: number | null } | null;

export type Quote = { price: number; level: PriceLevel; factor: number; baseQuantity: number };

const round = (n: number, d = 2) => Math.round((n + Number.EPSILON) * 10 ** d) / 10 ** d;

function wholesaleFor(product: PricedProduct, unit: PricedUnit): number | null {
  if (unit) {
    if (unit.wholesale_price !== null) return unit.wholesale_price;
    return product.wholesale_price !== null ? product.wholesale_price * unit.factor : null;
  }
  return product.wholesale_price;
}

export function quote(product: PricedProduct, unit: PricedUnit, quantity: number, level: PriceLevel, autoWholesale: boolean): Quote {
  const factor = unit ? Math.max(0.001, unit.factor) : 1;
  const baseQuantity = round(quantity * factor, 3);
  let effective: PriceLevel = level;
  const minimum = product.wholesale_min_qty ?? 0;
  if (level === 'retail' && minimum > 0 && baseQuantity + 0.0005 >= minimum && autoWholesale) effective = 'wholesale';
  const wholesale = wholesaleFor(product, unit);
  if (effective === 'wholesale' && wholesale === null) effective = 'retail';
  const price = effective === 'wholesale' ? (wholesale as number) : unit ? unit.retail_price : product.selling_price;
  return { price: round(price), level: effective, factor, baseQuantity };
}

export function levelFor(customerType: string | null | undefined, chosen: PriceLevel | null): PriceLevel {
  if (chosen) return chosen;
  return customerType === 'wholesale' ? 'wholesale' : 'retail';
}
