export type PlanStatus = "draft" | "pending" | "partially_received" | "received" | "cancelled" | "closed";

export const PLAN_STATUS_LABELS: Record<PlanStatus, string> = {
  draft: "Draft", pending: "Pending (ordered)", partially_received: "Partly received", received: "Received", cancelled: "Cancelled", closed: "Closed",
};
export const PLAN_STATUS_TONES: Record<PlanStatus, "neutral" | "green" | "saffron" | "red" | "blue" | "navy"> = {
  draft: "neutral", pending: "blue", partially_received: "saffron", received: "green", cancelled: "red", closed: "navy",
};
export const PLAN_EVENT_LABELS: Record<string, string> = {
  created: "Created", updated: "Changed", pending: "Made pending (ordered)", partially_received: "Goods received (part)", received: "All goods received", cancelled: "Cancelled", closed: "Closed",
};

export type PlanItem = {
  id: number; product_id: number; quantity: string; received_quantity: string; remaining_quantity: number; unit_cost: string; line_total: string; note: string | null;
  product?: { id: number; name: string; sku: string | null; cost_price?: string; track_expiry?: boolean; unit?: { short_name: string } | null };
};

export type PurchasePlan = {
  id: number; number: string; title: string | null; status: PlanStatus; branch_id: number; supplier_id: number | null;
  supplier?: { id: number; name: string; phone?: string | null } | null; branch?: { id: number; name: string } | null;
  expected_date: string | null; payment_type: "cash" | "credit"; credit_days: number | null; reminder_days_before: number | null; reminder_channels: string[] | null;
  estimated_total: string; received_total: string; notes: string | null; cancel_reason: string | null; created_at: string;
  items_count?: number; items?: PlanItem[]; creator?: { name: string } | null;
  purchases?: Array<{ id: number; number: string; purchase_date: string; total: string; balance: string; payment_status: string; due_date: string | null; remind_on: string | null }>;
  events?: Array<{ id: number; event: string; note: string | null; created_at: string; user?: { name: string } | null }>;
};
