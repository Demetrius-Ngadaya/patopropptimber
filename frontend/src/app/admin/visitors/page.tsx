"use client";

import { useEffect, useState } from "react";
import { Globe2, Laptop, MapPin, Monitor, Smartphone, Tablet } from "lucide-react";
import type { ApiResponse } from "@/lib/api";
import { useApi, usePaginated } from "@/lib/hooks";
import { cn, dateTime } from "@/lib/format";
import { useLocale, useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, DataTable, PageHeader, Pagination, Panel, SearchInput, Spinner, Stat, Tabs } from "@/components/ui";

type Named = { name: string; total: number };
type Summary = {
  online_now: number; online_by_kind: Record<string, number>; today: number; yesterday: number; this_week: number; this_month: number; this_year: number; all_time: number;
  new_today: number; page_views_today: number;
  daily: Array<{ day: string; visitors: number; page_views: number }>;
  countries: Named[]; cities: Named[]; devices: Named[]; browsers: Named[]; referrers: Named[];
  top_pages: Array<{ path: string; views: number }>;
};
type Visitor = {
  id: number; kind: "guest" | "customer" | "staff"; is_online: boolean; first_seen_at: string; last_seen_at: string; visits: number; page_views: number;
  current_path: string | null; landing_path: string | null; referrer: string | null; ip: string | null; country: string | null; region: string | null; city: string | null;
  latitude: number | null; longitude: number | null; device: string | null; browser: string | null; os: string | null; language: string | null;
  user?: { id: number; name: string; username?: string | null; type: string } | null;
};
type Period = "online" | "today" | "week" | "month" | "year" | "all";

/** Who visits the website and online shop: online now, today, this week, month, year and all time. */
export default function VisitorsPage() {
  const t = useT();
  const { locale } = useLocale();
  const [period, setPeriod] = useState<Period>("online");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const summary = useApi<ApiResponse<Summary>>("admin/visitors/summary");
  const list = usePaginated<Visitor>("admin/visitors", { period, search: search || undefined, page, per_page: 25 });
  const s = summary.data?.data;

  // Live: refresh every 30 seconds while the page is open.
  const refetchSummary = summary.refetch;
  const refetchList = list.refetch;
  useEffect(() => {
    const id = setInterval(() => { if (document.visibilityState === "visible") { void refetchSummary(); void refetchList(); } }, 30_000);
    return () => clearInterval(id);
  }, [refetchSummary, refetchList]);

  const countryName = (code?: string | null): string | null => {
    if (!code) return null;
    try {
      return new Intl.DisplayNames([locale === "sw" ? "sw" : "en"], { type: "region" }).of(code) ?? code;
    } catch {
      return code;
    }
  };
  const place = (v: Visitor) => [v.city, v.region && v.region !== v.city && !/^\d+$/.test(v.region) ? v.region : null, countryName(v.country)].filter(Boolean).join(", ");

  return (
    <Guard perm="visitors.view">
      <PageHeader title={t("Website visitors")} description={t("Everyone who opens the website or online shop, counted once per browser. Updates every 30 seconds.")} />

      {!s ? <Spinner variant="dashboard" /> : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <Stat label={t("Online now")} value={<span className="flex items-center gap-2"><span className="relative flex size-2.5"><span className="absolute inline-flex size-full animate-ping rounded-full bg-brand-500 opacity-60" /><span className="relative inline-flex size-2.5 rounded-full bg-brand-600" /></span>{s.online_now}</span>}
              sub={Object.entries(s.online_by_kind).map(([k, n]) => `${n} ${t(KIND_LABEL[k] ?? k)}`).join(" · ") || undefined} tone="brand" />
            <Stat label={t("Today")} value={s.today} sub={t("{count} new · {views} pages", { count: String(s.new_today), views: String(s.page_views_today) })} />
            <Stat label={t("This week")} value={s.this_week} sub={t("Yesterday: {count}", { count: String(s.yesterday) })} />
            <Stat label={t("This month")} value={s.this_month} />
            <Stat label={t("This year")} value={s.this_year} />
            <Stat label={t("All time")} value={s.all_time} />
          </div>

          <Panel title={t("Visitors per day (last 30 days)")} className="mt-5">
            <DailyChart rows={s.daily} />
          </Panel>

          <div className="mt-5 grid gap-5 lg:grid-cols-3">
            <TopList title={t("Countries")} icon={<Globe2 className="size-4" />} rows={s.countries.map((r) => ({ label: countryName(r.name) ?? r.name, value: r.total }))} empty={t("Location shows once the site runs on Vercel or Cloudflare.")} />
            <TopList title={t("Cities")} icon={<MapPin className="size-4" />} rows={s.cities.map((r) => ({ label: r.name, value: r.total }))} empty={t("Location shows once the site runs on Vercel or Cloudflare.")} />
            <TopList title={t("Most opened pages (7 days)")} icon={<Monitor className="size-4" />} rows={s.top_pages.map((r) => ({ label: r.path, value: Number(r.views) }))} mono />
            <TopList title={t("Devices")} icon={<Smartphone className="size-4" />} rows={s.devices.map((r) => ({ label: t(DEVICE_LABEL[r.name] ?? r.name), value: r.total }))} />
            <TopList title={t("Browsers")} icon={<Laptop className="size-4" />} rows={s.browsers.map((r) => ({ label: r.name, value: r.total }))} />
            <TopList title={t("Came from")} icon={<Globe2 className="size-4" />} rows={s.referrers.map((r) => ({ label: r.name, value: r.total }))} empty={t("Visitors who typed the address or used a bookmark.")} />
          </div>
        </>
      )}

      <Panel className="mt-5" padded={false} title={t("Visitors")}
        actions={<SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("City, country, IP, page or name")} />}>
        <div className="px-3 pt-1">
          <Tabs<Period> value={period} onChange={(v) => { setPeriod(v); setPage(1); }} tabs={[
            { value: "online", label: t("Online now"), count: s?.online_now }, { value: "today", label: t("Today") }, { value: "week", label: t("This week") },
            { value: "month", label: t("This month") }, { value: "year", label: t("This year") }, { value: "all", label: t("All time") },
          ]} />
        </div>
        <DataTable<Visitor>
          loading={list.isLoading}
          rows={list.data?.data}
          rowKey={(v) => v.id}
          empty={{ title: period === "online" ? t("Nobody is on the site right now") : t("No visitors in this period") }}
          columns={[
            { key: "who", header: t("Visitor"), render: (v) => (
              <div className="flex items-center gap-2.5">
                <span className={cn("size-2.5 shrink-0 rounded-full", v.is_online ? "bg-brand-500" : "bg-ink-300")} title={v.is_online ? t("Online now") : t("Offline")} />
                <div className="min-w-0">
                  <p className="truncate font-medium text-ink-900">{v.user?.name ?? t("Guest #{id}", { id: String(v.id) })}</p>
                  <Badge tone={v.kind === "staff" ? "navy" : v.kind === "customer" ? "green" : "neutral"}>{t(KIND_ONE[v.kind] ?? v.kind)}</Badge>
                </div>
              </div>
            ) },
            { key: "place", header: t("Location"), render: (v) => {
              const where = place(v);
              const map = v.latitude != null && v.longitude != null ? `https://maps.google.com/?q=${v.latitude},${v.longitude}` : null;
              return where ? (map ? <a href={map} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">{where}</a> : where) : <span className="text-ink-400">{t("Unknown")}</span>;
            } },
            { key: "device", header: t("Device"), hideOnMobile: true, render: (v) => (
              <span className="flex items-center gap-1.5 text-ink-700">{DEVICE_ICON[v.device ?? ""] ?? <Monitor className="size-4" />}{[v.browser, v.os].filter(Boolean).join(" · ")}</span>
            ) },
            { key: "current_path", header: t("Page"), hideOnMobile: true, render: (v) => <span className="font-mono text-xs text-ink-700">{v.current_path ?? "—"}</span> },
            { key: "activity", header: t("Visits / pages"), align: "right", hideOnMobile: true, render: (v) => <span className="num">{v.visits} / {v.page_views}</span> },
            { key: "ip", header: t("IP address"), hideOnMobile: true, render: (v) => <span className="num text-xs text-ink-600">{v.ip ?? "—"}</span> },
            { key: "last_seen_at", header: t("Last seen"), align: "right", render: (v) => (
              <span className="text-xs text-ink-600">{v.is_online ? <span className="font-medium text-brand-700">{t("Now")}</span> : dateTime(v.last_seen_at)}<span className="block text-ink-400">{t("First: {date}", { date: dateTime(v.first_seen_at) })}</span></span>
            ) },
          ]}
        />
        <div className="px-3 pb-3"><Pagination meta={list.data?.meta} onPage={setPage} /></div>
      </Panel>
    </Guard>
  );
}

const KIND_LABEL: Record<string, string> = { guest: "guests", customer: "customers", staff: "staff" };
const KIND_ONE: Record<string, string> = { guest: "Guest", customer: "Customer", staff: "Staff" };
const DEVICE_LABEL: Record<string, string> = { mobile: "Phone", tablet: "Tablet", desktop: "Computer" };
const DEVICE_ICON: Record<string, React.ReactNode> = { mobile: <Smartphone className="size-4" />, tablet: <Tablet className="size-4" />, desktop: <Monitor className="size-4" /> };

/** Bars of unique visitors per day, the page views written under each bar on hover. */
function DailyChart({ rows }: { rows: Summary["daily"] }) {
  const t = useT();
  const max = Math.max(1, ...rows.map((r) => r.visitors));
  return (
    <div>
      <div className="flex h-40 items-end gap-1" role="img" aria-label={t("Visitors per day (last 30 days)")}>
        {rows.map((r) => (
          <div key={r.day} className="group relative flex h-full flex-1 flex-col justify-end">
            <div className="w-full rounded-t-sm bg-brand-500/80 transition-colors group-hover:bg-brand-600" style={{ height: `${Math.max(r.visitors ? 4 : 0, (r.visitors / max) * 100)}%` }} />
            <span className="pointer-events-none absolute -top-8 left-1/2 z-10 hidden -translate-x-1/2 whitespace-nowrap rounded bg-night-900 px-2 py-1 text-[11px] text-white group-hover:block">
              {new Date(r.day).toLocaleDateString("en-GB", { day: "numeric", month: "short" })}: {r.visitors} · {t("{views} pages", { views: String(r.page_views) })}
            </span>
          </div>
        ))}
      </div>
      <div className="mt-1.5 flex justify-between text-[11px] text-ink-500">
        <span>{rows[0] && new Date(rows[0].day).toLocaleDateString("en-GB", { day: "numeric", month: "short" })}</span>
        <span>{t("Today")}</span>
      </div>
    </div>
  );
}

function TopList({ title, icon, rows, empty, mono }: { title: string; icon: React.ReactNode; rows: Array<{ label: string; value: number }>; empty?: string; mono?: boolean }) {
  const t = useT();
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <Panel title={<span className="flex items-center gap-2">{icon}{title}</span>}>
      {!rows.length ? <p className="text-sm text-ink-500">{empty ?? t("Nothing yet.")}</p> : (
        <ul className="space-y-2">
          {rows.map((r) => (
            <li key={r.label}>
              <div className="flex justify-between gap-3 text-sm"><span className={cn("truncate text-ink-800", mono && "font-mono text-xs")}>{r.label}</span><span className="num text-ink-600">{r.value}</span></div>
              <div className="mt-1 h-1.5 rounded-full bg-ink-100"><div className="h-full rounded-full bg-brand-500/70" style={{ width: `${(r.value / max) * 100}%` }} /></div>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
