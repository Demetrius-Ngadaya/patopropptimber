"use client";

import Link from "next/link";
import { use, useState } from "react";
import { ArrowLeft, FileDown, FileSpreadsheet } from "lucide-react";
import { toast } from "sonner";
import { download, ApiError, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dateTime, money, monthStart, qty, today, humanize } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useLookups } from "@/lib/lookups";
import { Button, ErrorState, Field, Input, PageHeader, Pagination, Panel, SearchInput, Select, Spinner } from "@/components/ui";
import { CustomerPicker } from "@/features/crm/components/CustomerPicker";
import type { Customer } from "@/lib/types";
import { useT } from "@/lib/i18n";

type Column = { key: string; label: string; type: string };
type ReportResponse = {
  data: Record<string, unknown>[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  report: { title: string; columns: Column[]; totals: Record<string, number>; summary: Record<string, number | string> | null; summary_labels?: Record<string, string>; period: { from: string; to: string }; filters: string[] };
};

function get(row: Record<string, unknown>, key: string): unknown {
  // Laravel serialises relations in snake_case: fromBranch -> from_branch
  return key.split(".").reduce<unknown>((acc, k) => (acc == null ? acc : (acc as Record<string, unknown>)[k]), row);
}

function format(value: unknown, type: string): React.ReactNode {
  if (value === null || value === undefined || value === "") return <span className="text-ink-300">—</span>;
  switch (type) {
    case "money": return money(value);
    case "number": return qty(value);
    case "date": return date(String(value));
    case "datetime": return dateTime(String(value));
    default: return typeof value === "object" ? JSON.stringify(value) : String(value);
  }
}

/** Summary tiles that are counts, not money. */
const COUNT_KEYS = new Set(["invoices", "items", "days", "still_open", "purchases", "months", "documents", "draft", "pending", "partially_received", "received", "cancelled", "closed", "buying_customers", "customers", "entries", "transactions", "paid", "not_paid"]);

/** Names of the report filters (English keys, translated when shown). */
const FILTER_LABELS: Record<string, string> = {
  supplier_id: "Supplier", category_id: "Category", cashier_id: "Cashier", user_id: "User", seller_id: "Seller", expense_category_id: "Expense category", product_id: "Product",
  agency_account_id: "Line", payment_status: "Payment", status: "Status", channel: "Channel", credit_status: "Credit", stock_status: "Stock level", expiry_status: "Expiry",
  sort: "Sort by", condition: "Condition", transfer_status: "Status", transfer_type: "Type", direction: "Direction", allocation_status: "Status",
  due_status: "When to pay", online_status: "Status", online_purpose: "For", plan_status: "Stage", doc_stage: "Stage", movement_type: "Movement",
};

const selectOptions: Record<string, Array<[string, string]>> = {
  payment_status: [["", "Any payment"], ["paid", "Paid"], ["partial", "Part paid"], ["unpaid", "Unpaid"]],
  status: [["completed", "Completed"], ["cancelled", "Cancelled"], ["all", "All"]],
  channel: [["", "Any channel"], ["pos", "Till (POS)"], ["online", "Online shop"], ["order", "Staff orders"], ["allocation", "Seller allocations"]],
  credit_status: [["unpaid", "Unpaid, sold in period"], ["due", "Due in period"], ["overdue", "Overdue now"], ["paid", "Cleared in period"]],
  stock_status: [["", "Any level"], ["low", "Low"], ["out", "Out of stock"], ["ok", "Healthy"]],
  expiry_status: [["all", "Expired + expiring"], ["expiring", "Expiring soon"], ["expired", "Already expired"]],
  sort: [["top_revenue", "Best by revenue"], ["bottom_revenue", "Worst by revenue"], ["top_quantity", "Most units sold"], ["bottom_quantity", "Fewest units sold"], ["top_profit", "Most profit"]],
  condition: [["", "Any condition"], ["restock", "Returned to stock"], ["damaged", "Damaged"]],
  transfer_status: [["", "Any status"], ["in_transit", "In transit"], ["received", "Received"], ["cancelled", "Cancelled"]],
  transfer_type: [["", "Any type"], ["free", "Free"], ["sale", "Sale"], ["credit", "Credit"]],
  direction: [["all", "In and out"], ["in", "Coming in"], ["out", "Going out"]],
  allocation_status: [["", "Any status"], ["open", "Open"], ["reconciled", "Reconciled"], ["cancelled", "Cancelled"]],
  due_status: [["", "Everything owed"], ["overdue", "Overdue"], ["week", "Due in 7 days"]],
  online_status: [["", "Any status"], ["completed", "Paid"], ["unused", "Not used yet"], ["pending", "Waiting for PIN"], ["failed", "Failed"], ["expired", "Expired"], ["cancelled", "Stopped"]],
  online_purpose: [["", "Till and shop"], ["pos", "Till"], ["order", "Shop order"]],
  plan_status: [["", "Any stage"], ["draft", "Draft"], ["pending", "Pending (ordered)"], ["partially_received", "Partly received"], ["received", "Received"], ["cancelled", "Cancelled"], ["closed", "Closed"]],
  doc_stage: [["", "Any status"], ...["draft", "sent", "accepted", "declined", "expired", "converted", "awaiting_payment", "owing", "overdue", "paid", "closed", "cancelled"].map((t) => [t, humanize(t)] as [string, string])],
  movement_type: [["", "Any movement"], ...["purchase", "sale", "sale_return", "transfer_in", "transfer_out", "adjustment_in", "adjustment_out", "damage", "expired"].map((t) => [t, humanize(t)] as [string, string])],
};

export default function ReportViewer({ params }: { params: Promise<{ key: string }> }) {
  const tr = useT();
  const { key } = use(params);
  const { can } = useAuth();
  const lookups = useLookups(["categories", "suppliers", "staff", "expense_categories"]);
  const agencyLines = useApi<ApiResponse<{ accounts: Array<{ id: number; name: string }> }>>(key.startsWith("agency") ? "admin/agency/accounts" : null, { all: 1 });
  const [filters, setFilters] = useState<Record<string, string>>({ date_from: monthStart(), date_to: today() });
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [page, setPage] = useState(1);
  const [exporting, setExporting] = useState<string | null>(null);
  const debounced = useDebounced(filters, 350);
  const query = { ...debounced, customer_id: customer?.id, page, per_page: 50 };
  const q = useApi<ReportResponse>(`admin/reports/${key}`, query);
  const r = q.data?.report;
  const set = (k: string, v: string) => { setFilters((f) => ({ ...f, [k]: v })); setPage(1); };

  const exportAs = async (format: "xlsx" | "pdf") => {
    setExporting(format);
    try {
      await download(`admin/reports/${key}`, { ...query, page: undefined, per_page: undefined, export: format });
      toast.success(tr("{value} file downloaded.", { value: format === "pdf" ? "PDF" : "Excel" }));
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : tr("Export failed."));
    } finally {
      setExporting(null);
    }
  };

  const filterControl = (name: string) => {
    if (name === "date_from" || name === "date_to") return <Field key={name} label={name === "date_from" ? tr("From") : tr("To")}>{(p) => <Input {...p} type="date" value={filters[name] ?? ""} onChange={(e) => set(name, e.target.value)} />}</Field>;
    if (name === "customer_id") return <Field key={name} label={tr("Customer")}>{() => <CustomerPicker value={customer} onChange={(c) => { setCustomer(c); setPage(1); }} />}</Field>;
    if (name === "search") return <Field key={name} label={tr("Search")}>{() => <SearchInput value={filters.search ?? ""} onChange={(v) => set("search", v)} />}</Field>;
    if (name === "days" || name === "limit") return <Field key={name} label={name === "days" ? tr("Within days") : tr("How many")}>{(p) => <Input {...p} type="number" min={1} value={filters[name] ?? ""} onChange={(e) => set(name, e.target.value)} />}</Field>;
    const lists: Record<string, Array<{ id: number; name: string }> | undefined> = { agency_account_id: agencyLines.data?.data.accounts, category_id: lookups.categories, supplier_id: lookups.suppliers, cashier_id: lookups.staff, user_id: lookups.staff, seller_id: lookups.staff, expense_category_id: lookups.expense_categories };
    if (lists[name] !== undefined || name.endsWith("_id")) {
      return (
        <Field key={name} label={tr(FILTER_LABELS[name] ?? humanize(name.replace(/_id$/, "")))}>
          {(p) => <Select {...p} value={filters[name] ?? ""} onChange={(e) => set(name, e.target.value)}><option value="">{tr("All")}</option>{(lists[name] ?? []).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</Select>}
        </Field>
      );
    }
    if (selectOptions[name]) {
      return <Field key={name} label={tr(FILTER_LABELS[name] ?? humanize(name))}>{(p) => <Select {...p} value={filters[name] ?? selectOptions[name][0][0]} onChange={(e) => set(name, e.target.value)}>{selectOptions[name].map(([v, l]) => <option key={v} value={v}>{tr(l)}</option>)}</Select>}</Field>;
    }
    return null;
  };

  return (
    <>
      <PageHeader
        back={<Link href="/admin/reports" className="mb-2 inline-flex items-center gap-1 text-sm text-ink-500 hover:text-ink-800"><ArrowLeft className="size-4" /> {tr("Reports")}</Link>}
        title={r?.title ?? tr("Report")}
        description={r ? tr("{from} to {to}", { from: date(r.period.from), to: date(r.period.to) }) : undefined}
        actions={can("reports.export") && (
          <>
            <Button variant="secondary" icon={<FileSpreadsheet className="size-4" />} loading={exporting === "xlsx"} onClick={() => exportAs("xlsx")}>{tr("Excel")}</Button>
            <Button variant="secondary" icon={<FileDown className="size-4" />} loading={exporting === "pdf"} onClick={() => exportAs("pdf")}>{tr("PDF")}</Button>
          </>
        )}
      />
      {r && <Panel className="mb-5"><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">{r.filters.map(filterControl)}</div></Panel>}
      {q.isLoading && <Spinner />}
      {q.error && <ErrorState message={q.error.message} onRetry={() => q.refetch()} />}
      {r && r.summary && (
        <div className="mb-5 flex flex-wrap gap-3">
          {Object.entries(r.summary).map(([k, v]) => (
            <div key={k} className="rounded-[var(--radius-panel)] bg-surface px-4 py-3 ring-1 ring-ink-200/70">
              <p className="text-[13px] text-ink-500">{r.summary_labels?.[k] ?? tr(humanize(k))}</p>
              <p className="num text-lg font-semibold">{typeof v === "number" ? (k.includes("quantity") || COUNT_KEYS.has(k) ? qty(v) : money(v)) : v}</p>
            </div>
          ))}
        </div>
      )}
      {r && q.data && (
        <Panel padded={false}>
          <div className={`overflow-x-auto ${q.isFetching ? "opacity-60" : ""}`}>
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-ink-200 bg-ink-50/60">
                  {r.columns.map((c) => <th key={c.key} className={`whitespace-nowrap px-3 py-2.5 text-[12.5px] font-semibold text-ink-600 ${c.type === "money" || c.type === "number" ? "text-right" : "text-left"}`}>{c.label}</th>)}
                </tr>
              </thead>
              <tbody>
                {q.data.data.map((row, i) => (
                  <tr key={i} className="border-b border-ink-100">
                    {r.columns.map((c) => {
                      const value = get(row, c.key) ?? get(row, c.key.replace(/([a-z])_?([A-Z])/g, "$1_$2").toLowerCase());
                      return <td key={c.key} className={`whitespace-nowrap px-3 py-2 ${c.type === "money" || c.type === "number" ? "num text-right" : ""}`}>{format(value, c.type)}</td>;
                    })}
                  </tr>
                ))}
                {!q.data.data.length && <tr><td colSpan={r.columns.length} className="px-3 py-10 text-center text-ink-500">{tr("No records for these filters.")}</td></tr>}
              </tbody>
              {Object.keys(r.totals).length > 0 && q.data.data.length > 0 && (
                <tfoot>
                  <tr className="bg-ink-50 font-semibold">
                    {r.columns.map((c, i) => <td key={c.key} className={`px-3 py-2.5 ${c.type === "money" || c.type === "number" ? "num text-right" : ""}`}>{i === 0 ? tr("Total (all pages)") : c.key in r.totals ? money(r.totals[c.key]) : ""}</td>)}
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
          <Pagination meta={q.data.meta} onPage={setPage} />
        </Panel>
      )}
    </>
  );
}
