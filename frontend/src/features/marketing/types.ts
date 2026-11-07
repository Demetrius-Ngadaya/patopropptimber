import type { Segment } from "./segments";

export type Promotion = {
  id: number;
  code: string;
  name: string;
  description: string | null;
  type: "percent" | "amount";
  value: string;
  applies_to: "all" | "categories" | "products";
  category_ids: number[] | null;
  product_ids: number[] | null;
  min_purchase: string | null;
  max_discount: string | null;
  audience: "all" | "segments" | "customers";
  segments: Segment[] | null;
  customer_ids: number[] | null;
  starts_on: string;
  ends_on: string;
  uses_per_customer: number | null;
  is_active: boolean;
  notified_at: string | null;
  state: "running" | "scheduled" | "ended" | "paused";
  redemptions_count?: number;
  redemptions_sum_amount?: string | null;
  deleted_at?: string | null;
};

export type PromotionDetail = Promotion & {
  audience_size: number;
  sales_value: number;
  products: Array<{ id: number; name: string; sku: string | null }>;
  customers: Array<{ id: number; name: string; phone: string | null }>;
  recent_redemptions: Array<{ id: number; amount: string; created_at: string; sale?: { id: number; number: string; total: string; sold_at: string } | null; customer?: { id: number; name: string } | null }>;
};

export const STATE_LABELS: Record<Promotion["state"], string> = { running: "Running", scheduled: "Starts later", ended: "Ended", paused: "Paused" };
export const STATE_TONES: Record<Promotion["state"], "green" | "blue" | "neutral" | "saffron"> = { running: "green", scheduled: "blue", ended: "neutral", paused: "saffron" };

export function offerText(p: Pick<Promotion, "type" | "value">, t: (s: string, v?: Record<string, string | number>) => string, currency = ""): string {
  const v = Number(p.value);
  return p.type === "percent" ? t("{value}% off", { value: v }) : t("{currency} {value} off", { currency, value: v.toLocaleString() }).trim();
}
