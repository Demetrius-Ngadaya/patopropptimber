"use client";

import Link from "next/link";
import { useState } from "react";
import { RefreshCw } from "lucide-react";
import { api, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money, monthStart, today } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Input, PageHeader, Pagination, Panel, SearchInput, Select, Stat, Tabs } from "@/components/ui";
import { ONLINE_STATUS_LABELS, ONLINE_STATUS_TONES, PURPOSE_LABELS, type OnlinePayment, type OnlinePaymentStatus } from "@/features/payments/types";

type Summary = { received: number; fees: number; count: number; unused: number; waiting: number; failed: number };
type Filter = "" | OnlinePaymentStatus | "unused";

export default function OnlinePaymentsPage() {
  const t = useT();
  const { can } = useAuth();
  const [status, setStatus] = useState<Filter>("");
  const [purpose, setPurpose] = useState("");
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [checking, setChecking] = useState<number | null>(null);
  const list = useApi<Paginated<OnlinePayment> & { summary: Summary }>("admin/online-payments", { status, purpose, from, to, search: useDebounced(search), page });
  const s = list.data?.summary;

  const check = async (id: number) => {
    setChecking(id);
    try {
      await api.get(`admin/online-payments/${id}`, { force: 1 });
      await list.refetch();
    } finally {
      setChecking(null);
    }
  };

  return (
    <Guard perm="online_payments.view">
      <PageHeader
        title={t("Payments by phone")}
        description={t("Mobile money requested by a prompt on the customer's phone, at the till and for online orders. Money paid but not used for a sale needs attention.")}
        actions={can("settings.view") && <Link href="/admin/settings"><Button variant="secondary">{t("Settings")}</Button></Link>}
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={t("Received")} value={money(s?.received ?? 0)} sub={t("{count} payments", { count: s?.count ?? 0 })} tone="brand" />
        <Stat label={t("Snippe fees")} value={money(s?.fees ?? 0)} />
        <Stat label={t("Paid, not used for a sale")} value={money(s?.unused ?? 0)} tone={(s?.unused ?? 0) > 0 ? "ember" : "default"} />
        <Stat label={t("Waiting / not paid")} value={`${s?.waiting ?? 0} / ${s?.failed ?? 0}`} />
      </div>
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[
            { value: "" as Filter, label: t("All") },
            { value: "completed" as Filter, label: t("Paid") },
            { value: "unused" as Filter, label: t("Not used yet") },
            { value: "pending" as Filter, label: t("Waiting for PIN") },
            { value: "failed" as Filter, label: t("Failed") },
            { value: "expired" as Filter, label: t("Expired") },
          ]} />
        </div>
        <div className="flex flex-wrap items-end gap-2 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Number, phone, name or transaction ID")} className="w-full max-w-sm" />
          <div className="w-40">
            <Select aria-label={t("For")} value={purpose} onChange={(e) => { setPurpose(e.target.value); setPage(1); }}>
              <option value="">{t("Till and shop")}</option>
              <option value="pos">{t("Till")}</option>
              <option value="order">{t("Shop order")}</option>
            </Select>
          </div>
          <div className="w-40"><Input type="date" aria-label={t("From")} value={from} max={to} onChange={(e) => { setFrom(e.target.value || monthStart()); setPage(1); }} /></div>
          <div className="w-40"><Input type="date" aria-label={t("To")} value={to} min={from} onChange={(e) => { setTo(e.target.value || today()); setPage(1); }} /></div>
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          columns={[
            { key: "number", header: t("Payment"), render: (r) => <span className="font-medium">{r.number}<span className="block text-xs font-normal text-ink-500">{dateTime(r.created_at)}</span></span> },
            { key: "purpose", header: t("For"), hideOnMobile: true, render: (r) => <span>{t(PURPOSE_LABELS[r.purpose])}<span className="block text-xs text-ink-500">{r.requester?.name ?? r.branch?.name}</span></span> },
            { key: "payer", header: t("Customer"), render: (r) => <span>{r.payer_name ?? r.customer?.name ?? "—"}<span className="block text-xs text-ink-500">{r.phone}</span></span> },
            { key: "provider", header: t("Network"), hideOnMobile: true, render: (r) => <span>{r.provider_name ?? "—"}{r.external_reference && <span className="block text-xs text-ink-500">{r.external_reference}</span>}</span> },
            { key: "amount", header: t("Amount"), align: "right", render: (r) => <span className="num">{money(r.amount)}{Number(r.fee) > 0 && <span className="block text-xs text-ink-500">{t("fee {amount}", { amount: money(r.fee) })}</span>}</span> },
            {
              key: "used", header: t("Used for"), hideOnMobile: true, render: (r) => r.sale ? <Link className="text-brand-700 hover:underline" href={`/admin/sales/${r.sale.id}`}>{r.sale.number}</Link>
                : r.order ? <Link className="text-brand-700 hover:underline" href={`/admin/orders/${r.order.id}`}>{r.order.number}</Link>
                : r.is_usable ? <Badge tone="saffron">{t("Not used yet")}</Badge> : <span className="text-ink-400">—</span>,
            },
            {
              key: "status", header: t("Status"), render: (r) => (
                <span className="flex items-center justify-end gap-1 sm:justify-start">
                  <Badge tone={ONLINE_STATUS_TONES[r.status]}>{t(ONLINE_STATUS_LABELS[r.status])}</Badge>
                  {r.status === "pending" && (
                    <Button size="sm" variant="ghost" aria-label={t("Check now")} loading={checking === r.id} onClick={() => check(r.id)}><RefreshCw className="size-4" /></Button>
                  )}
                  {r.failure_reason && r.status !== "completed" && <span className="sr-only">{r.failure_reason}</span>}
                </span>
              ),
            },
          ]}
          empty={{ title: t("No payments by phone in this period") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
