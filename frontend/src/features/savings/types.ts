export type SavingsAccount = {
  id: number;
  account_no: string;
  customer_id: number;
  customer?: { id: number; name: string; phone: string | null; address: string | null; balance?: string };
  branch?: { id: number; name: string } | null;
  status: "active" | "frozen" | "closed";
  balance: string;
  total_deposited: string;
  total_spent: string;
  total_withdrawn: string;
  last_transaction_at: string | null;
  notes: string | null;
  created_at: string;
  deleted_at?: string | null;
};

export type SavingsTransaction = {
  id: number;
  number: string;
  type: "deposit" | "purchase" | "withdrawal" | "refund" | "debt_payment" | "reversal";
  amount: string;
  balance_before: string;
  balance_after: string;
  purchase_amount: string | null;
  debt_amount: string | null;
  method: string | null;
  provider: string | null;
  reference: string | null;
  notes: string | null;
  transacted_at: string;
  reversed_transaction_id: number | null;
  sale?: { id: number; number: string; total: string } | null;
  creator?: { id: number; name: string } | null;
  customer?: { id: number; name: string; phone: string | null } | null;
};

export const SAVINGS_TYPE_LABELS: Record<SavingsTransaction["type"], string> = {
  deposit: "Deposit",
  purchase: "Purchase",
  withdrawal: "Withdrawal",
  refund: "Refund",
  debt_payment: "Debt payment",
  reversal: "Reversal",
};

export const MONEY_METHODS = ["cash", "mobile_money", "lipa_namba", "bank", "cheque", "card"];
