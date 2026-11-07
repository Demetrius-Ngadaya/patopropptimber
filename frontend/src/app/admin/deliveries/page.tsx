"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { MessageCircle } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { dateTime, money } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, DataTable, PageHeader, Pagination, Panel, SearchInput, Switch, Tabs } from "@/components/ui";
import { DELIVERY_STATUS_LABELS, DELIVERY_STATUS_TONES, type Delivery } from "@/features/delivery/types";

type Filter = "open" | "delivered" | "received" | "failed" | "cancelled" | "";

export default function DeliveriesPage() {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const staff = can(["deliveries.view", "deliveries.assign"]);
  const [status, setStatus] = useState<Filter>("open");
  const [mine, setMine] = useState(false);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  // Refreshes by itself so new jobs and replies show up.
  const list = useApi<Paginated<Delivery>>("admin/deliveries", { status, mine: mine || undefined, search: useDebounced(search), page });

  return (
    <Guard perm={["deliveries.view", "deliveries.assign", "deliveries.own"]}>
      <PageHeader
        title={staff ? t("Deliveries") : t("My deliveries")}
        description={staff ? t("Orders and sales on their way to customers. Give one to a delivery person from the order or the sale.") : t("Goods to take to customers. Open one to see the map, start the trip and chat with the customer.")}
      />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs<Filter> value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[
            { value: "open", label: t("To deliver") }, { value: "delivered", label: t("Waiting for confirmation") }, { value: "received", label: t("Received") },
            { value: "failed", label: t("Could not deliver") }, { value: "cancelled", label: t("Cancelled") }, { value: "", label: t("All") },
          ]} />
        </div>
        <div className="flex flex-wrap items-center gap-4 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Number, customer or phone")} className="w-full max-w-sm" />
          {staff && can("deliveries.own") && <div className="w-56"><Switch label={t("Only mine")} checked={mine} onChange={setMine} /></div>}
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/deliveries/${r.id}`)}
          columns={[
            { key: "number", header: t("Delivery"), render: (r) => <span className="font-medium text-ink-900">{r.number}<span className="block text-xs font-normal text-ink-500">{r.order?.number ?? r.sale?.number}</span></span> },
            { key: "customer", header: t("Customer"), render: (r) => <span>{r.customer_name}<span className="block max-w-56 truncate text-xs text-ink-500">{r.address ?? r.contact_phone ?? ""}</span></span> },
            { key: "courier", header: t("Delivery person"), hideOnMobile: true, render: (r) => r.courier?.name ?? "—" },
            { key: "fee", header: t("Fee"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.final_fee)}{r.distance_km && <span className="block text-xs text-ink-500">{t("{km} km", { km: Number(r.distance_km) })}</span>}</span> },
            { key: "assigned_at", header: t("Given"), hideOnMobile: true, render: (r) => dateTime(r.assigned_at) },
            {
              key: "status", header: t("State"), render: (r) => (
                <span className="flex items-center gap-1.5">
                  <Badge tone={DELIVERY_STATUS_TONES[r.status]}>{t(DELIVERY_STATUS_LABELS[r.status])}</Badge>
                  {(r.unread_messages ?? 0) > 0 && <span className="inline-flex items-center gap-0.5 rounded-full bg-ember-600 px-1.5 text-[11px] font-semibold text-white" title={t("New messages")}><MessageCircle className="size-3" />{r.unread_messages}</span>}
                </span>
              ),
            },
          ]}
          empty={{ title: status === "open" ? t("Nothing to deliver right now") : t("No deliveries here") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
