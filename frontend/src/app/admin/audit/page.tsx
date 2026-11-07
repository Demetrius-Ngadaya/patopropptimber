"use client";

import { useState } from "react";
import { FileSpreadsheet, FileText } from "lucide-react";
import { download, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, humanize } from "@/lib/format";
import { useApi, useDebounced } from "@/lib/hooks";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, StatusBadge, Tabs } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Activity = { id: number; user_name: string | null; user_type: string | null; method: string; action: string | null; path: string; status_code: number; duration_ms: number; payload: Record<string, unknown> | null; ip_address: string | null; user_agent: string | null; created_at: string };
type Change = { id: number; log_type: string; event: string; user_name: string | null; auditable_type: string | null; auditable_id: number | null; description: string | null; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null; ip_address: string | null; created_at: string };
type Login = { id: number; identifier: string; status: string; failure_reason: string | null; ip_address: string | null; user_agent: string | null; logged_in_at: string | null; logged_out_at: string | null; logout_reason: string | null; created_at: string };
type TabKey = "changes" | "activity" | "logins";

const typeTones: Record<string, "blue" | "navy" | "saffron" | "red"> = { data: "blue", auth: "navy", business: "saffron", security: "red" };

function show(v: unknown): string {
  if (v === null || v === undefined || v === "") return "—";
  if (typeof v === "object") return JSON.stringify(v);
  return String(v);
}

export default function AuditPage() {
  const tr = useT();
  const { can } = useAuth();
  const available = ([
    ["changes", "Data & security changes", "audit.changes"],
    ["activity", "User activity", "audit.activity"],
    ["logins", "Logins", "audit.logins"],
  ] as const).filter(([, , p]) => can(p));
  const [tab, setTab] = useState<TabKey>(available[0]?.[0] ?? "changes");
  const [search, setSearch] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [filter, setFilter] = useState("");
  const [page, setPage] = useState(1);
  const [detail, setDetail] = useState<Change | Activity | null>(null);
  const q = useDebounced(search);

  const filterKey = tab === "changes" ? "log_type" : "status";
  const query = { search: q, date_from: from, date_to: to, [filterKey]: filter, page };
  const list = useApi<Paginated<Change & Activity & Login>>(`admin/audit/${tab}`, query);
  const switchTab = (t: TabKey) => { setTab(t); setFilter(""); setPage(1); };
  const exportAs = (format: "xlsx" | "pdf") => download(`admin/audit/${tab}`, { ...query, page: undefined, export: format });

  const columns = {
    changes: [
      { key: "created_at", header: tr("When"), render: (r: Change) => <span className="whitespace-nowrap">{dateTime(r.created_at)}</span> },
      { key: "log_type", header: tr("Type"), render: (r: Change) => <Badge tone={typeTones[r.log_type]}>{r.log_type}</Badge> },
      { key: "user_name", header: tr("By"), render: (r: Change) => r.user_name ?? "System" },
      { key: "description", header: tr("What happened"), render: (r: Change) => <span>{r.description ?? humanize(r.event)}{r.auditable_type && <span className="block text-xs text-ink-500">{humanize(r.auditable_type)} #{r.auditable_id}</span>}</span> },
      { key: "ip_address", header: tr("IP"), hideOnMobile: true },
    ],
    activity: [
      { key: "created_at", header: tr("When"), render: (r: Activity) => <span className="whitespace-nowrap">{dateTime(r.created_at)}</span> },
      { key: "user_name", header: tr("User"), render: (r: Activity) => r.user_name ?? "Guest" },
      { key: "action", header: tr("Action"), render: (r: Activity) => <span>{r.action ?? r.path}<span className="block font-mono text-xs text-ink-500">{r.method} /{r.path}</span></span> },
      { key: "status_code", header: tr("Result"), render: (r: Activity) => <Badge tone={r.status_code >= 400 ? "red" : "green"}>{r.status_code}</Badge> },
      { key: "duration_ms", header: tr("Time"), align: "right" as const, hideOnMobile: true, render: (r: Activity) => <span className="num">{tr("{duration_ms} ms", { duration_ms: r.duration_ms })}</span> },
      { key: "ip_address", header: tr("IP"), hideOnMobile: true },
    ],
    logins: [
      { key: "created_at", header: tr("When"), render: (r: Login) => <span className="whitespace-nowrap">{dateTime(r.created_at)}</span> },
      { key: "identifier", header: tr("Username typed") },
      { key: "status", header: tr("Result"), render: (r: Login) => <span><StatusBadge status={r.status} />{r.failure_reason && <span className="block text-xs text-ink-500">{r.failure_reason}</span>}</span> },
      { key: "logged_out_at", header: tr("Logged out"), hideOnMobile: true, render: (r: Login) => r.logged_out_at ? <span>{dateTime(r.logged_out_at)}<span className="block text-xs text-ink-500">{tr(humanize(r.logout_reason ?? ""))}</span></span> : r.status === "success" ? <Badge tone="green">{tr("Active / expired")}</Badge> : "—" },
      { key: "ip_address", header: tr("IP / device"), hideOnMobile: true, render: (r: Login) => <span>{r.ip_address}<span className="block max-w-56 truncate text-xs text-ink-500" title={r.user_agent ?? ""}>{r.user_agent}</span></span> },
    ],
  }[tab];

  return (
    <Guard perm={["audit.activity", "audit.changes", "audit.logins"]}>
      <PageHeader title={tr("Audit logs")} description={tr("Every change, request and login, with who did it, when and from where. Logs cannot be edited or deleted.")}
        actions={can("audit.export") && <><Button variant="secondary" icon={<FileSpreadsheet className="size-4" />} onClick={() => exportAs("xlsx")}>{tr("Excel")}</Button><Button variant="secondary" icon={<FileText className="size-4" />} onClick={() => exportAs("pdf")}>{tr("PDF")}</Button></>} />
      <Tabs value={tab} onChange={switchTab} tabs={available.map(([v, l]) => ({ value: v, label: l }))} />
      <Panel padded={false} className="mt-4">
        <div className="flex flex-wrap items-center gap-3 border-b border-ink-100 p-3">
          <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={tab === "logins" ? tr("Username or IP") : tr("Search")} className="w-full max-w-xs" />
          <Select aria-label={tr("Filter")} className="w-44" value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1); }}>
            {tab === "changes" && <><option value="">{tr("All types")}</option><option value="data">{tr("Data changes")}</option><option value="auth">{tr("Sign-in & account")}</option><option value="business">{tr("Business events")}</option><option value="security">{tr("Security")}</option></>}
            {tab === "activity" && <><option value="">{tr("All requests")}</option><option value="failed">{tr("Failed only")}</option></>}
            {tab === "logins" && <><option value="">{tr("All attempts")}</option><option value="success">{tr("Successful")}</option><option value="failed">{tr("Failed")}</option><option value="locked">{tr("Locked")}</option><option value="inactive">{tr("Disabled account")}</option></>}
          </Select>
          <Input aria-label={tr("From")} type="date" className="w-40" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
          <Input aria-label={tr("To")} type="date" className="w-40" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
        </div>
        {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id} columns={columns as any}
          onRowClick={tab === "logins" ? undefined : (r) => setDetail(r)} empty={{ title: tr("Nothing recorded for these filters") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>

      <Modal open={Boolean(detail)} onClose={() => setDetail(null)} title={tr("Log entry")} size="lg">
        {detail && "old_values" in detail && (
          <div className="space-y-3">
            <p className="text-sm">{detail.description} · {dateTime(detail.created_at)} · {detail.user_name ?? tr("System")}</p>
            {(detail.old_values || detail.new_values) && (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="text-left text-ink-500"><th className="py-1.5 pr-3 font-medium">{tr("Field")}</th><th className="py-1.5 pr-3 font-medium">{tr("Before")}</th><th className="py-1.5 font-medium">{tr("After")}</th></tr></thead>
                  <tbody>
                    {[...new Set([...Object.keys(detail.old_values ?? {}), ...Object.keys(detail.new_values ?? {})])].map((k) => (
                      <tr key={k} className="border-t border-ink-100 align-top">
                        <td className="py-1.5 pr-3 font-medium">{humanize(k)}</td>
                        <td className="break-all py-1.5 pr-3 text-ember-700">{show(detail.old_values?.[k])}</td>
                        <td className="break-all py-1.5 text-brand-700">{show(detail.new_values?.[k])}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
        {detail && "payload" in detail && (
          <div className="space-y-3 text-sm">
            <p><span className="font-mono">{detail.method} /{detail.path}</span> {tr("→ {status_code} in {duration_ms} ms", { status_code: detail.status_code, duration_ms: detail.duration_ms })}</p>
            <p className="text-ink-600">{detail.user_name ?? tr("Guest")} · {detail.ip_address} · {dateTime(detail.created_at)}</p>
            <p className="break-all text-xs text-ink-500">{detail.user_agent}</p>
            <pre className="max-h-80 overflow-auto rounded-md bg-ink-50 p-3 text-xs">{detail.payload ? JSON.stringify(detail.payload, null, 2) : "No data sent"}</pre>
            <p className="text-xs text-ink-500">{tr("Passwords, tokens and other secrets are removed before saving.")}</p>
          </div>
        )}
      </Modal>
    </Guard>
  );
}
