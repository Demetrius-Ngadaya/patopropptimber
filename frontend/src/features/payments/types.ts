export type OnlinePaymentStatus = "pending" | "completed" | "failed" | "expired" | "voided" | "cancelled";

export type OnlinePayment = {
  id: number;
  number: string;
  purpose: "pos" | "order";
  branch_id: number;
  order_id: number | null;
  sale_id: number | null;
  customer_id: number | null;
  amount: string;
  phone: string;
  payer_name: string | null;
  status: OnlinePaymentStatus;
  reference: string | null;
  external_reference: string | null;
  provider: string | null;
  provider_name: string | null;
  fee: string;
  failure_reason: string | null;
  expires_at: string | null;
  completed_at: string | null;
  used_at: string | null;
  created_at: string;
  is_final: boolean;
  is_usable: boolean;
  branch?: { id: number; name: string };
  sale?: { id: number; number: string } | null;
  order?: { id: number; number: string } | null;
  customer?: { id: number; name: string } | null;
  requester?: { id: number; name: string } | null;
};

export type OnlinePaymentsStatus = {
  configured: boolean;
  enabled: boolean;
  pos: boolean;
  shop: boolean;
  webhook_secret: boolean;
  webhook_url: string;
  webhook_https: boolean;
  minimum: number;
  balance?: { available: number; balance: number };
  test?: { ok: boolean; message: string };
};

export const ONLINE_STATUS_LABELS: Record<OnlinePaymentStatus, string> = {
  pending: "Waiting for PIN",
  completed: "Paid",
  failed: "Failed",
  expired: "Expired",
  voided: "Voided",
  cancelled: "Stopped",
};

export const ONLINE_STATUS_TONES: Record<OnlinePaymentStatus, "neutral" | "green" | "saffron" | "red" | "blue"> = {
  pending: "saffron",
  completed: "green",
  failed: "red",
  expired: "neutral",
  voided: "neutral",
  cancelled: "neutral",
};

export const PURPOSE_LABELS: Record<OnlinePayment["purpose"], string> = { pos: "Till", order: "Shop order" };

/** 0712 345 678, +255 712 345 678 or 255712345678. */
export const TZ_MOBILE = /^(?:\+?255|0)?[67]\d{8}$/;
export const isTzMobile = (v: string) => TZ_MOBILE.test(v.replace(/[\s-]/g, ""));
