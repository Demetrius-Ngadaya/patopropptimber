export type AiStatus = { enabled: boolean; assistant_available: boolean; provider: string; model: string | null; in_house_features: string[]; budget: { monthly_tokens: number; used: number } };

export type AiMessage = { id: number; role: "user" | "assistant"; content: string; tool_calls: Array<{ name: string; input?: unknown }> | null; created_at: string };
export type AiConversation = { id: number; title: string; last_message_at: string | null; messages?: AiMessage[] };

export type Insight = { key: string; type: "positive" | "warning" | "critical" | "info"; title: string; detail: string; link: string };
export type InsightReport = {
  metrics: { revenue: number; revenue_previous: number; revenue_change_pct: number | null; transactions: number; average_sale: number; gross_margin_pct: number | null; expenses: number };
  insights: Insight[];
  narrative: string | null;
  ai_available: boolean;
};

export type ReorderStatus = "out_of_stock" | "reorder_now" | "reorder_soon" | "dead_stock" | "overstock" | "ok";
export type ReorderRow = {
  product_id: number; name: string; sku: string | null; unit: string | null; on_hand: number; avg_daily_sales: number; forecast_daily: number; forecast_30_days: number;
  trend: "rising" | "falling" | "steady"; days_of_cover: number | null; reorder_point: number; safety_stock: number; suggested_order: number; suggested_order_cost: number;
  stockout_date: string | null; last_sale: string | null; stock_value: number; confidence: "high" | "medium" | "low"; status: ReorderStatus;
};
export type Forecast = {
  history: Array<{ date: string; quantity: number }>;
  forecast: Array<{ date: string; quantity: number; low: number; high: number }>;
  summary: { avg_daily_sales: number; forecast_daily: number; trend: string; weekday_profile: number[]; confidence: string };
};

export type Anomaly = { type: string; severity: "high" | "medium" | "low"; title: string; detail: string; subject: { type: string; id: number | string | null; name?: string }; action: string; amount: number };

export type CreditScoreRow = { customer_id: number; name: string; phone: string | null; balance: number; credit_limit: number; score: number; band: "A" | "B" | "C" | "D" | "E"; suggested_limit: number; overdue: number };
export type CreditScoreDetail = {
  customer: { id: number; name: string; phone: string | null; credit_limit: string; balance: string };
  score: number; band: CreditScoreRow["band"]; suggested_limit: number;
  factors: Array<{ key: string; label: string; points: number; max: number; reason: string }>;
  summary: { credit_sales: number; settled: number; outstanding: number; overdue: number; avg_monthly_purchases: number; current_limit: number };
  history: Array<{ score: number; band: string; computed_at: string }>;
};

export const BAND_TONE = { A: "green", B: "green", C: "saffron", D: "red", E: "red" } as const;
export const REORDER_TONE: Record<ReorderStatus, "red" | "saffron" | "blue" | "neutral" | "green"> = { out_of_stock: "red", reorder_now: "red", reorder_soon: "saffron", dead_stock: "neutral", overstock: "blue", ok: "green" };
export const REORDER_LABEL: Record<ReorderStatus, string> = { out_of_stock: "Out of stock", reorder_now: "Order now", reorder_soon: "Order soon", dead_stock: "Not selling", overstock: "Too much stock", ok: "OK" };
