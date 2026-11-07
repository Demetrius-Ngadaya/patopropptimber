"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import type { Paginated } from "@/lib/api";
import { cn, date, money, monthStart, today } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, DataTable, Field, Input, PageHeader, Pagination, Panel } from "@/components/ui";
import { AgencyNav } from "@/features/agency/AgencyNav";
import type { AgencyDay } from "@/features/agency/types";

export default function AgencyDaysPage() {
  const t = useT();
  const router = useRouter();
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [page, setPage] = useState(1);
  const list = useApi<Paginated<AgencyDay>>("admin/agency/days", { date_from: from, date_to: to, page });

  return (
    <Guard perm="agency.view">
      <PageHeader title={t("Uwakala days")} description={t("Every opening and closing, with the difference after capital put in or taken out.")} />
      <AgencyNav />
      <Panel padded={false}>
        <div className="flex flex-wrap gap-3 border-b border-ink-100 p-3">
          <Field label={t("From")}>{(p) => <Input {...p} type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />}</Field>
          <Field label={t("To")}>{(p) => <Input {...p} type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />}</Field>
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/agency/days/${r.id}`)}
          columns={[
            { key: "business_date", header: t("Date"), render: (r) => <span className="font-medium">{date(r.business_date)}<span className="block text-xs font-normal text-ink-500">{r.branch?.name}</span></span> },
            { key: "opening_total", header: t("Total at opening"), align: "right", render: (r) => money(r.opening_total) },
            { key: "closing_total", header: t("Total at closing"), align: "right", render: (r) => r.closing_total === null ? "—" : money(r.closing_total) },
            { key: "capital", header: t("Capital in / out"), align: "right", hideOnMobile: true, render: (r) => `+${money(r.capital_added)} / −${money(r.capital_withdrawn)}` },
            {
              key: "difference", header: t("Short / over"), align: "right", render: (r) => r.difference === null ? "—" : (
                <span className={cn("font-semibold", r.difference < 0 ? "text-ember-600" : r.difference > 0 ? "text-saffron-700" : "text-brand-700")}>{r.difference > 0 ? "+" : ""}{money(r.difference)}</span>
              ),
            },
            { key: "status", header: t("State"), render: (r) => <Badge tone={r.status === "open" ? "saffron" : "green"}>{t(r.status === "open" ? "Open" : "Closed")}</Badge> },
            { key: "people", header: t("Opened / closed by"), hideOnMobile: true, render: (r) => [r.opener?.name, r.closer?.name].filter(Boolean).join(" / ") },
          ]}
          empty={{ title: t("No days in this period") }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
    </Guard>
  );
}
