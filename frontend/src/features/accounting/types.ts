export type AccountType = "asset" | "liability" | "equity" | "income" | "expense";

export type Account = {
  id: number;
  code: string;
  name: string;
  type: AccountType;
  sub_type: string | null;
  parent_id: number | null;
  system_key: string | null;
  is_header: boolean;
  allow_manual: boolean;
  is_active: boolean;
  description: string | null;
  bank_name: string | null;
  account_number: string | null;
  balance: number;
  deleted_at?: string | null;
};

export type JournalLine = {
  id: number;
  account_id: number;
  account?: { id: number; code: string; name: string; type?: AccountType };
  debit: string;
  credit: string;
  memo: string | null;
  customer?: { id: number; name: string } | null;
  supplier?: { id: number; name: string } | null;
};

export type JournalEntry = {
  id: number;
  number: string;
  entry_date: string;
  description: string;
  reference: string | null;
  event: string | null;
  is_manual: boolean;
  status: "posted" | "reversed";
  total: string;
  reversal_of_id: number | null;
  reversed_by_id: number | null;
  reversal_of?: { id: number; number: string } | null;
  reversed_by?: { id: number; number: string } | null;
  branch?: { id: number; name: string } | null;
  creator?: { id: number; name: string } | null;
  lines: JournalLine[];
  created_at: string;
};

export type FiscalYear = { id: number; name: string; start_date: string; end_date: string; status: "open" | "closed"; closed_at: string | null };

export type StatementLine = { account_id: number | null; code: string | null; name: string; amount: number; sub_type?: string };

export const ACCOUNT_TYPES: AccountType[] = ["asset", "liability", "equity", "income", "expense"];
export const ACCOUNT_TYPE_LABELS: Record<AccountType, string> = { asset: "Assets", liability: "Liabilities", equity: "Equity", income: "Income", expense: "Expenses" };

/** Readable names for automatic posting events. */
export const EVENT_LABELS: Record<string, string> = {
  sale: "Sale", sale_return: "Sale return", debt_payment: "Debt payment", purchase: "Purchase", purchase_return: "Purchase return",
  supplier_payment: "Supplier payment", expense: "Expense", savings_deposit: "Savings deposit", savings_withdrawal: "Savings withdrawal",
  savings_deposit_reversed: "Savings deposit reversed", opening_stock: "Opening stock", stock_adjustment: "Stock adjustment",
  transfer_sent: "Transfer sent", transfer_received: "Transfer received", allocation_damage: "Allocation damage", payroll: "Payroll",
  payroll_payment: "Salaries paid", payroll_remittance: "Statutory remittance", salary_advance: "Salary advance", depreciation: "Depreciation",
  asset_acquired: "Asset bought", asset_disposed: "Asset disposed", year_end_close: "Year-end closing", manual: "Manual journal",
  quick_transfer: "Transfer between accounts", quick_capital: "Owner capital", quick_drawings: "Owner drawings", quick_other_income: "Other income",
  quick_loan_received: "Loan received", quick_loan_repayment: "Loan repayment",
};

export function eventLabel(event: string | null | undefined): string {
  if (!event) return "Manual journal";
  const base = event.replace(/_reversal$/, "");
  return EVENT_LABELS[base] ?? base.replace(/_/g, " ");
}

export type Reconciliation = { id: number; account_id: number; account?: { id: number; code: string; name: string }; statement_date: string; statement_balance: string; book_balance: string; cleared_balance: string; difference: string; status: "draft" | "completed"; completed_at: string | null; notes: string | null };
