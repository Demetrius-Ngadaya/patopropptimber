"use client";

import Link from "next/link";
import { notFound, useRouter } from "next/navigation";
import { use, useState } from "react";
import { Plus } from "lucide-react";
import type { Paginated } from "@/lib/api";
import { date, money, toNumber, today } from "@/lib/format";
import { useApi, useDebounced, useUrlParam } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, PageHeader, Pagination, Panel, SearchInput, Tabs } from "@/components/ui";
import { isDocType, STAGE_LABELS, STAGE_TONES, TYPE_META, type SalesDocument } from "@/features/documents/types";

type ListResponse = Paginated<SalesDocument> & { counts: Record<string, number>; stages: string[] };

export default function DocumentsPage({ params }: { params: Promise<{ type: string }> }) {
  const { type } = use(params);
  if (!isDocType(type)) notFound();
  return <DocumentList type={type} />;
}

function DocumentList({ type }: { type: "quotation" | "proforma" | "invoice" }) {
  const t = useT();
  const router = useRouter();
  const { can } = useAuth();
  const meta = TYPE_META[type];
  const [stage, setStage] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  useUrlParam("stage", setStage);
  const q = useDebounced(search);
  const list = useApi<ListResponse>("admin/documents", { type, stage, search: q, page });
  const counts = list.data?.counts ?? {};
  const stages = list.data?.stages ?? [];
  const all = Object.values(counts).reduce((a, b) => a + b, 0);
  const todayStr = today();

  return (
    <Guard perm="documents.view">
      <PageHeader
        title={t(meta.many)}
        description={t(meta.intro)}
        actions={can("documents.create") && <Link href={`/admin/documents/${type}/new`}><Button icon={<Plus className="size-4" />}>{t(meta.new)}</Button></Link>}
      />
      <Panel padded={false}>
        <div className="px-3 pt-2">
          <Tabs
            value={stage}
            onChange={(v) => { setStage(v); setPage(1); }}
            tabs={[{ value: "", label: t("All"), count: all }, ...stages.map((s) => ({ value: s, label: t(STAGE_LABELS[s] ?? s), count: counts[s] ?? 0 }))]}
          />
        </div>
        <div className="border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Number, customer name or phone")} className="w-full max-w-sm" />
        </div>
        <DataTable
          rows={list.data?.data}
          loading={list.isFetching}
          rowKey={(r) => r.id}
          onRowClick={(r) => router.push(`/admin/documents/${type}/${r.id}`)}
          columns={[
            { key: "number", header: t("Number"), render: (r) => <span className="font-medium text-ink-900">{r.number}<span className="block text-xs font-normal text-ink-500">{date(r.issue_date)}</span></span> },
            { key: "customer_name", header: t("Customer"), render: (r) => <span>{r.customer_name}<span className="block text-xs text-ink-500">{r.customer_phone ?? ""}</span></span> },
            { key: "total", header: t("Amount"), align: "right", render: (r) => <span className="num font-medium">{money(r.total)}</span> },
            ...(type === "quotation" ? [] : [{
              key: "balance", header: t("Balance"), align: "right" as const, hideOnMobile: true,
              render: (r: SalesDocument) => r.stage === "paid" || r.stage === "closed" ? <Badge tone="green">{t("Paid")}</Badge> : r.status === "draft" || r.stage === "cancelled" ? <span className="text-ink-300">—</span> : <span className="num font-semibold text-ember-600">{money(r.balance)}</span>,
            }]),
            {
              key: "due_date", header: t(meta.dueLabel), hideOnMobile: true, render: (r) => {
                if (!r.due_date) return "—";
                const late = ["owing", "overdue", "sent", "awaiting_payment"].includes(r.stage) && r.due_date.slice(0, 10) < todayStr;
                return <span className={late ? "font-medium text-ember-600" : undefined}>{date(r.due_date)}</span>;
              },
            },
            { key: "stage", header: t("Stage"), render: (r) => <Badge tone={STAGE_TONES[r.stage] ?? "neutral"}>{t(STAGE_LABELS[r.stage] ?? r.stage)}</Badge> },
            { key: "branch", header: t("Branch"), hideOnMobile: true, render: (r) => r.branch?.name ?? "—" },
          ]}
          empty={{
            title: stage ? t("Nothing at this stage") : t("No {kind} yet", { kind: t(meta.many).toLowerCase() }),
            action: !stage && can("documents.create") ? <Link href={`/admin/documents/${type}/new`}><Button icon={<Plus className="size-4" />}>{t(meta.new)}</Button></Link> : undefined,
          }}
        />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      {toNumber(counts.overdue) > 0 && stage !== "overdue" && (
        <p className="mt-3 text-sm text-ember-700">
          <button type="button" className="font-semibold underline underline-offset-2" onClick={() => { setStage("overdue"); setPage(1); }}>{t("{count} overdue: follow them up", { count: counts.overdue })}</button>
        </p>
      )}
    </Guard>
  );
}
