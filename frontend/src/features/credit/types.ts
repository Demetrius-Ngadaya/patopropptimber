export type CreditSaleRow = {
  id: number;
  number: string;
  customer_id: number | null;
  customer_name: string | null;
  customer_phone: string | null;
  customer?: { id: number; name: string; phone: string | null; address: string | null } | null;
  branch?: { id: number; name: string } | null;
  cashier?: { id: number; name: string } | null;
  sold_at: string;
  total: string;
  credit_amount: string;
  amount_paid: string;
  balance: string;
  payment_status: string;
  due_date: string | null;
  fully_paid_at: string | null;
};

export type CreditSummary = {
  sale_id: number;
  number: string;
  customer_name: string | null;
  customer_phone: string | null;
  customer_address: string | null;
  original_amount: number;
  amount_paid: number;
  remaining_balance: number;
  expected_repayment_date: string | null;
  days_overdue: number;
  status: "unpaid" | "partially_paid" | "fully_paid";
  fully_paid_at: string | null;
};

export type CreditPayment = {
  id: number;
  number: string;
  paid_at: string;
  amount: number;
  balance_before: number;
  balance_after: number;
  method: string;
  reference: string | null;
  received_by: string | null;
  customer_name: string | null;
  customer_phone: string | null;
  original_amount: number;
  expected_repayment_date: string | null;
  notes: string | null;
};

export const creditStatusTone = { unpaid: "red", partially_paid: "saffron", fully_paid: "green" } as const;
