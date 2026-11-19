"use client";

import { useListState } from "@/lib/list-state";
import { usePaginated } from "@/lib/hooks";
import { dateTime } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, DataTable, PageHeader, Pagination, Panel, SearchInput, Spinner, Stat, Tabs } from "@/components/ui";

type Install = {
  id: number; device_model: string | null; platform: string; os_version: string | null; app_version: string | null; build_number: number | null;
  opens: number; first_seen_at: string | null; last_seen_at: string | null; status: "signed_in" | "tried" | "never";
  logins: number; failed_logins: number; last_login_at: string | null; last_failed_at: string | null; last_failed_login: string | null;
  user: { id: number; name: string; username: string | null; phone: string | null; type: "staff" | "customer"; role: string | null } | null;
};
type Summary = {
  downloads: number; latest_version: string | null; latest_downloads: number; installs: number; signed_in: number; tried: number; never: number;
  active_7_days: number; on_latest: number; staff: number; customers: number; versions: { app_version: string | null; phones: number }[];
};
type Filter = "" | "signed_in" | "tried" | "never" | "active" | "inactive";

const STATUS: Record<Install["status"], { label: string; tone: "green" | "saffron" | "neutral" }> = {
  signed_in: { label: "Signed in", tone: "green" },
  tried: { label: "Tried, failed", tone: "saffron" },
  never: { label: "Never tried", tone: "neutral" },
};

/**
 * Who has the PatoPro app: downloads, phones that opened it, who signed in, who
 * tried and failed, who never tried, and which version each phone runs.
 */
export default function MobileAppUsersPage() {
  const t = useT();
  const [filter, setFilter] = useListState<Filter>("filter", "");
  const [search, setSearch] = useListState("search", "");
  const [page, setPage] = useListState("page", 1);
  const list = usePaginated<Install>("admin/mobile/installs", { status: filter || undefined, search: search || undefined, page });
  const s = (list.data as unknown as { summary?: Summary } | undefined)?.summary;

  return (
    <Guard perm="mobile_app.manage">
      <PageHeader title={t("Mobile app users")} description={t("Every phone the app was opened on, from the first time it opened: who signed in, who tried and failed, and who never tried.")} />

      {!s ? <Spinner variant="dashboard" /> : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
          <Stat label={t("Downloads")} value={s.downloads} sub={s.latest_version ? t("Version {version}: {count}", { version: s.latest_version, count: String(s.latest_downloads) }) : undefined} />
          <Stat label={t("Phones (opened the app)")} value={s.installs} sub={t("{staff} staff · {customers} customers", { staff: String(s.staff), customers: String(s.customers) })} tone="brand" />
          <Stat label={t("Signed in")} value={s.signed_in} />
          <Stat label={t("Tried, failed")} value={s.tried} sub={t("Wrong password or account")} />
          <Stat label={t("Never tried")} value={s.never} sub={t("Opened, but nobody signed in")} />
          <Stat label={t("Used in the last 7 days")} value={s.active_7_days} sub={s.latest_version ? t("{count} on the newest version", { count: String(s.on_latest) }) : undefined} />
        </div>
      )}

      {s && s.versions.length > 0 && (
        <p className="mt-3 flex flex-wrap gap-2 text-sm text-ink-600">
          <span className="font-medium text-ink-800">{t("Versions in use")}:</span>
          {s.versions.map((v) => <Badge key={v.app_version ?? "?"} tone="neutral">{v.app_version ?? t("Unknown")} · {v.phones}</Badge>)}
        </p>
      )}

      <Panel className="mt-5" padded={false} title={t("Phones")}
        actions={<SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Name, phone, username or device")} />}>
        <div className="px-3 pt-1">
          <Tabs<Filter> value={filter} onChange={(v) => { setFilter(v); setPage(1); }} tabs={[
            { value: "", label: t("All"), count: s?.installs }, { value: "signed_in", label: t("Signed in"), count: s?.signed_in },
            { value: "tried", label: t("Tried, failed"), count: s?.tried }, { value: "never", label: t("Never tried"), count: s?.never },
            { value: "active", label: t("Active (7 days)") }, { value: "inactive", label: t("Not used for 30 days") },
          ]} />
        </div>
        <DataTable<Install>
          loading={list.isLoading}
          rows={list.data?.data}
          rowKey={(i) => i.id}
          empty={{ title: t("No phones yet"), message: t("Phones appear here the first time the app is opened (version 1.0.4 and newer).") }}
          columns={[
            { key: "user", header: t("Who"), render: (i) => i.user ? (
              <div className="min-w-0">
                <p className="truncate font-medium text-ink-900">{i.user.name}</p>
                <p className="truncate text-xs text-ink-500">{[i.user.type === "customer" ? t("Customer") : i.user.role, i.user.phone ?? i.user.username].filter(Boolean).join(" · ")}</p>
              </div>
            ) : (
              <div className="min-w-0">
                <p className="text-ink-500">{t("Nobody signed in")}</p>
                {i.last_failed_login && <p className="truncate text-xs text-amber-700">{t("Last tried as: {name}", { name: i.last_failed_login })}</p>}
              </div>
            ), exportValue: (i) => i.user?.name ?? "" },
            { key: "status", header: t("Status"), render: (i) => <Badge tone={STATUS[i.status].tone}>{t(STATUS[i.status].label)}</Badge>, exportValue: (i) => t(STATUS[i.status].label) },
            { key: "attempts", header: t("Sign-ins / failed"), align: "right", render: (i) => <span className="num">{i.logins} / <span className={i.failed_logins ? "text-amber-700" : ""}>{i.failed_logins}</span></span>, exportValue: (i) => `${i.logins} / ${i.failed_logins}` },
            { key: "device", header: t("Phone"), hideOnMobile: true, render: (i) => <span className="text-ink-700">{i.device_model ?? "—"}<span className="block text-xs text-ink-400">{[i.platform === "ios" ? "iOS" : "Android", i.os_version].filter(Boolean).join(" ")}</span></span>, exportValue: (i) => i.device_model ?? "" },
            { key: "app_version", header: t("App version"), hideOnMobile: true, render: (i) => <span className="num">{i.app_version ?? "—"}{i.build_number ? <span className="text-ink-400"> ({i.build_number})</span> : null}</span> },
            { key: "opens", header: t("Opened"), align: "right", hideOnMobile: true, render: (i) => <span className="num">{i.opens}</span> },
            { key: "last_seen_at", header: t("Last used"), align: "right", render: (i) => (
              <span className="text-xs text-ink-600">{i.last_seen_at ? dateTime(i.last_seen_at) : "—"}<span className="block text-ink-400">{t("Installed: {date}", { date: i.first_seen_at ? dateTime(i.first_seen_at) : "—" })}</span></span>
            ), exportValue: (i) => i.last_seen_at ?? "" },
          ]}
        />
        <div className="px-3 pb-3"><Pagination meta={list.data?.meta} onPage={setPage} /></div>
      </Panel>
    </Guard>
  );
}
