import { FileCheck2, FileClock, FileText, type LucideIcon } from "lucide-react";

export type DocType = "quotation" | "proforma" | "invoice";
export const DOC_TYPES: DocType[] = ["quotation", "proforma", "invoice"];
export const isDocType = (v: string): v is DocType => (DOC_TYPES as string[]).includes(v);

export const TYPE_META: Record<DocType, { one: string; many: string; new: string; icon: LucideIcon; intro: string; dueLabel: string }> = {
  quotation: {
    one: "Quotation", many: "Quotations", new: "New quotation", icon: FileText, dueLabel: "Valid until",
    intro: "Prices offered to a customer. Send them, follow up, and turn accepted quotations into a proforma or an invoice.",
  },
  proforma: {
    one: "Proforma invoice", many: "Proforma invoices", new: "New proforma", icon: FileClock, dueLabel: "Valid until",
    intro: "A request for payment before the goods are released. When the customer pays in full it becomes a paid sale.",
  },
  invoice: {
    one: "Invoice", many: "Invoices", new: "New invoice", icon: FileCheck2, dueLabel: "Due date",
    intro: "Goods given now and paid later. Issuing an invoice records the sale and the customer's debt; payments reduce it.",
  },
};

/** Stage names as shown to people (English keys, translated in the UI). */
export const STAGE_LABELS: Record<string, string> = {
  draft: "Draft",
  sent: "Sent",
  accepted: "Accepted",
  declined: "Declined",
  expired: "Expired",
  converted: "Converted",
  awaiting_payment: "Awaiting payment",
  owing: "Owing",
  overdue: "Overdue",
  paid: "Fully paid",
  closed: "Closed",
  cancelled: "Cancelled",
};

export const STAGE_TONES: Record<string, "neutral" | "green" | "saffron" | "red" | "blue" | "navy"> = {
  draft: "neutral", sent: "blue", accepted: "green", declined: "red", expired: "red", converted: "navy",
  awaiting_payment: "saffron", owing: "saffron", overdue: "red", paid: "green", closed: "neutral", cancelled: "red",
};

export type DocItem = {
  id?: number;
  product_id: number;
  product_unit_id: number | null;
  description: string;
  unit_name: string | null;
  quantity: string | number;
  unit_price: string | number;
  discount: string | number;
  tax_rate: string | number;
  tax: string | number;
  line_total: string | number;
  product?: { id: number; name: string; sku: string | null } | null;
};

export type DocEvent = { id: number; event: string; note: string | null; created_at: string; user?: { id: number; name: string } | null };

export type SalesDocument = {
  id: number;
  type: DocType;
  number: string;
  status: string;
  stage: string;
  branch_id: number;
  branch?: { id: number; name: string } | null;
  customer_id: number | null;
  customer?: { id: number; name: string; phone: string | null; email?: string | null; [k: string]: unknown } | null;
  customer_name: string;
  customer_phone: string | null;
  customer_email: string | null;
  customer_address: string | null;
  customer_tin: string | null;
  customer_vrn: string | null;
  price_level: string | null;
  issue_date: string;
  due_date: string | null;
  subtotal: string;
  item_discount: string;
  discount: string;
  tax: string;
  total: string;
  amount_paid: number;
  balance: number;
  expired: boolean;
  notes: string | null;
  terms: string | null;
  sale_id: number | null;
  sale?: { id: number; number: string; status: string; total: string; amount_paid: string; balance: string } | null;
  converted_from?: { id: number; type: DocType; number: string } | null;
  converted_to?: { id: number; type: DocType; number: string } | null;
  sent_at: string | null;
  viewed_at: string | null;
  accepted_at: string | null;
  declined_at: string | null;
  decline_reason: string | null;
  cancel_reason: string | null;
  created_at: string;
  creator?: { id: number; name: string } | null;
  items?: DocItem[];
  events?: DocEvent[];
};

export type DocumentDetail = SalesDocument & {
  title: string;
  editable: boolean;
  public_url: string;
  payments: Array<{ id: number; number: string; type: string; amount: string; method: string; provider: string | null; reference: string | null; paid_at: string }>;
};

export const EVENT_LABELS: Record<string, string> = {
  created: "Created",
  updated: "Changed",
  sent: "Sent to the customer",
  viewed: "Opened by the customer",
  accepted: "Accepted",
  declined: "Declined",
  issued: "Issued",
  payment: "Payment received",
  paid: "Fully paid",
  converted: "Converted",
  closed: "Closed",
  cancelled: "Cancelled",
  reopened: "Reopened",
};
