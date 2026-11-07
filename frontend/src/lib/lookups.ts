"use client";

import { useApi } from "./hooks";
import type { ApiResponse } from "./api";
import type { Branch } from "./types";

export type Lookups = {
  branches?: Branch[];
  categories?: Array<{ id: number; name: string; parent_id: number | null }>;
  units?: Array<{ id: number; name: string; short_name: string; allow_decimal: boolean }>;
  expense_categories?: Array<{ id: number; name: string }>;
  suppliers?: Array<{ id: number; name: string; code: string; phone: string | null; balance: string }>;
  staff?: Array<{ id: number; name: string; username: string; branch_id: number | null }>;
  /** online: payment by phone (USSD push) is switched on for the till and this user may use it. */
  payment?: { methods: string[]; providers: string[]; online?: boolean; online_minimum?: number };
};

export function useLookups(include: Array<keyof Lookups>) {
  const q = useApi<ApiResponse<Lookups>>("admin/lookups", { include: include.join(",") });
  return q.data?.data ?? {};
}

export const paymentMethodLabels: Record<string, string> = {
  cash: "Cash",
  mobile_money: "Mobile money",
  lipa_namba: "Lipa namba",
  online: "Pay by phone (push)",
  bank: "Bank",
  card: "Card",
  cheque: "Cheque",
  credit_note: "Credit note",
  savings: "Customer savings",
};
