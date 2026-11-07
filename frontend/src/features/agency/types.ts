export type AgencyAccount = {
  id: number;
  branch_id: number | null;
  branch?: { id: number; name: string } | null;
  name: string;
  provider: string;
  kind: "mobile_money" | "bank" | "other";
  agent_number: string | null;
  is_active: boolean;
  sort_order: number;
  notes: string | null;
};

export type Provider = { key: string; name: string; kind: string };

export type DayBalance = { id: number; agency_account_id: number; opening_float: string; closing_float: string | null; account?: AgencyAccount };

export type AgencyDay = {
  id: number;
  branch_id: number;
  branch?: { id: number; name: string } | null;
  business_date: string;
  status: "open" | "closed";
  opening_cash: string;
  closing_cash: string | null;
  capital_added: string;
  capital_withdrawn: string;
  opening_float: number;
  opening_total: number;
  closing_float: number | null;
  closing_total: number | null;
  difference: number | null;
  opened_at: string | null;
  closed_at: string | null;
  opening_note: string | null;
  closing_note: string | null;
  opener?: { id: number; name: string } | null;
  closer?: { id: number; name: string } | null;
  balances: DayBalance[];
};

export type TodayPayload = {
  date: string;
  day: AgencyDay | null;
  accounts: AgencyAccount[];
  suggestion: { from_date: string; cash: number; floats: Record<string, number> } | null;
  unclosed: { id: number; date: string } | null;
};

export type Commission = {
  id: number;
  agency_account_id: number;
  account?: { id: number; name: string; provider: string } | null;
  branch?: { id: number; name: string } | null;
  period: string;
  amount: string;
  received_on: string;
  reference: string | null;
  notes: string | null;
  creator?: { name: string } | null;
};

/** Brand-ish colour per provider, for the little badge next to each line. */
export const PROVIDER_COLORS: Record<string, string> = {
  mpesa: "bg-red-600", mixx: "bg-sky-600", airtel: "bg-rose-700", halopesa: "bg-orange-500", tpesa: "bg-blue-800", azampesa: "bg-cyan-700",
  nmb: "bg-indigo-700", crdb: "bg-emerald-700", nbc: "bg-slate-700", other: "bg-ink-500",
};

export const KIND_LABELS: Record<string, string> = { mobile_money: "Mobile money", bank: "Bank", other: "Other" };
