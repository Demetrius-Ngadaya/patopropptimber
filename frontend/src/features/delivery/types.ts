export type DeliveryStatus = "assigned" | "on_the_way" | "delivered" | "received" | "failed" | "cancelled";

export const DELIVERY_STATUS_LABELS: Record<DeliveryStatus, string> = {
  assigned: "Waiting to leave",
  on_the_way: "On the way",
  delivered: "Delivered",
  received: "Received by the customer",
  failed: "Could not deliver",
  cancelled: "Cancelled",
};
export const DELIVERY_STATUS_TONES: Record<DeliveryStatus, "neutral" | "green" | "saffron" | "red" | "blue"> = {
  assigned: "saffron", on_the_way: "blue", delivered: "green", received: "green", failed: "red", cancelled: "red",
};

export type Delivery = {
  id: number;
  number: string;
  status: DeliveryStatus;
  branch_id: number;
  branch?: { id: number; name: string; phone?: string | null; latitude?: string | null; longitude?: string | null } | null;
  order_id: number | null;
  sale_id: number | null;
  order?: { id: number; number: string; status: string; total: string; items?: Array<{ id: number; product_name: string; quantity: string; line_total: string }> } | null;
  sale?: { id: number; number: string; total: string; items?: Array<{ id: number; product_name: string; quantity: string; line_total: string }> } | null;
  customer_name: string;
  contact_phone: string | null;
  address: string | null;
  latitude: string | null;
  longitude: string | null;
  distance_km: string | null;
  fee: string;
  discount: string;
  discount_reason: string | null;
  final_fee: number;
  assigned_to: number;
  courier?: { id: number; name: string; phone: string | null } | null;
  assigned_at: string | null;
  picked_up_at: string | null;
  delivered_at: string | null;
  received_at: string | null;
  failed_reason: string | null;
  note: string | null;
  courier_latitude: string | null;
  courier_longitude: string | null;
  courier_located_at: string | null;
  unread_messages?: number;
};

export type DeliveryDetail = Delivery & {
  trail: Array<[number, number, number]>;
  origin: [number, number] | null;
  tracking_url: string;
  can_act: boolean;
};

export type ChatMessage = { id: number; sender: "staff" | "customer"; name: string | null; body: string; read: boolean; created_at: string };
