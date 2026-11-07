"use client";

import { useState } from "react";
import { CalendarClock, Plus } from "lucide-react";
import { api, type ApiResponse, type Paginated } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, money, toNumber, today } from "@/lib/format";
import { fieldErrors, useAction, useApi, useDebounced } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Guard } from "@/components/layout/Guard";
import { Badge, Button, DataTable, Field, Input, Modal, PageHeader, Pagination, Panel, SearchInput, Select, Stat, Tabs, Textarea } from "@/components/ui";
import { useAccounts } from "@/features/accounting/components/AccountSelect";

type Asset = {
  id: number; number: string; name: string; category: string | null; serial_number: string | null; location: string | null;
  acquired_on: string; cost: string; salvage_value: string; useful_life_months: number; accumulated_depreciation: string;
  book_value: number; monthly_depreciation: number; status: "active" | "disposed" | "fully_depreciated"; disposed_on: string | null; disposal_amount: string | null;
  asset_account?: { id: number; code: string; name: string };
};
type Summary = { cost: number; accumulated_depreciation: number; book_value: number };

function RegisterDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const accounts = useAccounts({ postable: true }).filter((a) => a.sub_type === "fixed_asset" && !/accumulated/i.test(a.name));
  const [f, setF] = useState({ name: "", category: "", serial_number: "", location: "", asset_account_id: "" as number | "", acquired_on: today(), cost: "", salvage_value: "", useful_life_months: "60", funding: "cash", notes: "" });
  const save = useAction(() => api.post("admin/accounting/fixed-assets", { ...f, cost: Number(f.cost), salvage_value: Number(f.salvage_value) || 0, useful_life_months: Number(f.useful_life_months) }), { invalidate: [["admin/accounting/fixed-assets"]], onSuccess: onClose });
  const e = fieldErrors(save.error);
  const monthly = (toNumber(f.cost) - toNumber(f.salvage_value)) / Math.max(1, toNumber(f.useful_life_months));
  return (
    <Modal open={open} onClose={onClose} size="lg" title={t("Register an asset")} description={t("Furniture, equipment, vehicles, fridges, buildings... Depreciation is calculated monthly (straight line).")}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={!f.name || !f.asset_account_id || !Number(f.cost)} onClick={() => save.mutate(undefined)}>{t("Register")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Name")} required error={e.name}>{(p) => <Input {...p} value={f.name} onChange={(ev) => setF({ ...f, name: ev.target.value })} placeholder={t("e.g. Display fridge")} />}</Field>
        <Field label={t("Asset account")} required error={e.asset_account_id}>{(p) => <Select {...p} value={f.asset_account_id} onChange={(ev) => setF({ ...f, asset_account_id: Number(ev.target.value) || "" })}><option value="">{t("Choose…")}</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</Select>}</Field>
        <Field label={t("Bought on")} required error={e.acquired_on}>{(p) => <Input {...p} type="date" max={today()} value={f.acquired_on} onChange={(ev) => setF({ ...f, acquired_on: ev.target.value })} />}</Field>
        <Field label={t("Cost")} required error={e.cost}>{(p) => <Input {...p} type="number" min={0} step="any" className="num text-right" value={f.cost} onChange={(ev) => setF({ ...f, cost: ev.target.value })} />}</Field>
        <Field label={t("Useful life (months)")} required error={e.useful_life_months} hint={monthly > 0 ? t("{amount} per month", { amount: money(monthly) }) : undefined}>{(p) => <Input {...p} type="number" min={1} max={600} value={f.useful_life_months} onChange={(ev) => setF({ ...f, useful_life_months: ev.target.value })} />}</Field>
        <Field label={t("Value at the end (salvage)")} error={e.salvage_value}>{(p) => <Input {...p} type="number" min={0} step="any" className="num text-right" value={f.salvage_value} onChange={(ev) => setF({ ...f, salvage_value: ev.target.value })} />}</Field>
        <Field label={t("Paid with")} hint={t("Already owned before using PatoPro? Choose \"Opening balance\".")}>{(p) => <Select {...p} value={f.funding} onChange={(ev) => setF({ ...f, funding: ev.target.value })}>
          <option value="cash">{t("Cash")}</option><option value="bank">{t("Bank")}</option><option value="mobile_money">{t("Mobile money")}</option><option value="payable">{t("On credit from a supplier")}</option><option value="opening">{t("Opening balance")}</option></Select>}</Field>
        <Field label={t("Category")}>{(p) => <Input {...p} value={f.category} onChange={(ev) => setF({ ...f, category: ev.target.value })} />}</Field>
        <Field label={t("Serial number")}>{(p) => <Input {...p} value={f.serial_number} onChange={(ev) => setF({ ...f, serial_number: ev.target.value })} />}</Field>
        <Field label={t("Location")}>{(p) => <Input {...p} value={f.location} onChange={(ev) => setF({ ...f, location: ev.target.value })} />}</Field>
        <Field label={t("Notes")} className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={f.notes} onChange={(ev) => setF({ ...f, notes: ev.target.value })} />}</Field>
      </div>
    </Modal>
  );
}

function DisposeDialog({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const t = useT();
  const [f, setF] = useState({ disposed_on: today(), amount: "", method: "cash", reason: "" });
  const save = useAction(() => api.post(`admin/accounting/fixed-assets/${asset.id}/dispose`, { ...f, amount: Number(f.amount) || 0, method: Number(f.amount) ? f.method : undefined }), { invalidate: [["admin/accounting/fixed-assets"]], onSuccess: onClose });
  return (
    <Modal open onClose={onClose} title={t("Sell or write off {name}", { name: asset.name })} description={t("Book value now: {value}", { value: money(asset.book_value) })}
      footer={<><Button variant="secondary" onClick={onClose}>{t("Cancel")}</Button><Button loading={save.isPending} disabled={f.reason.trim().length < 3} onClick={() => save.mutate(undefined)}>{t("Dispose")}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t("Date")} required>{(p) => <Input {...p} type="date" max={today()} value={f.disposed_on} onChange={(ev) => setF({ ...f, disposed_on: ev.target.value })} />}</Field>
        <Field label={t("Sold for (0 if thrown away)")}>{(p) => <Input {...p} type="number" min={0} step="any" className="num text-right" value={f.amount} onChange={(ev) => setF({ ...f, amount: ev.target.value })} />}</Field>
        {Number(f.amount) > 0 && <Field label={t("Received into")}>{(p) => <Select {...p} value={f.method} onChange={(ev) => setF({ ...f, method: ev.target.value })}><option value="cash">{t("Cash")}</option><option value="bank">{t("Bank")}</option><option value="mobile_money">{t("Mobile money")}</option></Select>}</Field>}
        <Field label={t("Reason")} required className="sm:col-span-2">{(p) => <Textarea {...p} rows={2} value={f.reason} onChange={(ev) => setF({ ...f, reason: ev.target.value })} />}</Field>
      </div>
    </Modal>
  );
}

export default function FixedAssetsPage() {
  const t = useT();
  const { can } = useAuth();
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [registering, setRegistering] = useState(false);
  const [disposing, setDisposing] = useState<Asset | null>(null);
  const [period, setPeriod] = useState(() => { const d = new Date(); d.setMonth(d.getMonth() - 1); return d.toISOString().slice(0, 7); });
  const q = useDebounced(search);
  const list = useApi<Paginated<Asset> & { summary: Summary }>("admin/accounting/fixed-assets", { status, search: q, page });
  const depreciate = useAction(() => api.post<ApiResponse<{ amount: number }>>("admin/accounting/fixed-assets/depreciate", { period }), { invalidate: [["admin/accounting/fixed-assets"]] });
  const s = list.data?.summary;
  const tone = { active: "green", fully_depreciated: "neutral", disposed: "red" } as const;

  return (
    <Guard perm={["accounting.view", "accounting.fixed_assets"]}>
      <PageHeader title={t("Fixed assets")} description={t("Everything the business owns for long-term use, with monthly depreciation posted to the books.")}
        actions={can("accounting.fixed_assets") && (
          <>
            <div className="flex items-center gap-1.5"><Input aria-label={t("Depreciate up to month")} type="month" className="w-40" value={period} onChange={(e) => setPeriod(e.target.value)} /><Button variant="secondary" icon={<CalendarClock className="size-4" />} loading={depreciate.isPending} onClick={() => depreciate.mutate(undefined)}>{t("Run depreciation")}</Button></div>
            <Button icon={<Plus className="size-4" />} onClick={() => setRegistering(true)}>{t("Register asset")}</Button>
          </>
        )} />
      {s && <div className="mb-5 grid grid-cols-3 gap-3"><Stat label={t("Cost")} value={money(s.cost)} /><Stat label={t("Depreciated so far")} value={money(s.accumulated_depreciation)} /><Stat label={t("Book value")} value={money(s.book_value)} tone="brand" /></div>}
      <Panel padded={false}>
        <div className="px-3 pt-2"><Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} tabs={[{ value: "", label: t("All") }, { value: "active", label: t("In use") }, { value: "fully_depreciated", label: t("Fully depreciated") }, { value: "disposed", label: t("Disposed") }]} /></div>
        <div className="border-b border-ink-100 p-3"><SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("Name, number or serial")} className="w-full max-w-sm" /></div>
        <DataTable rows={list.data?.data} loading={list.isFetching} rowKey={(r) => r.id}
          columns={[
            { key: "name", header: t("Asset"), render: (r) => <span className="font-medium text-ink-900">{r.name}<span className="block text-xs font-normal text-ink-500">{r.number}{r.location ? ` · ${r.location}` : ""}</span></span> },
            { key: "acquired_on", header: t("Bought"), hideOnMobile: true, render: (r) => date(r.acquired_on) },
            { key: "cost", header: t("Cost"), align: "right", render: (r) => <span className="num">{money(r.cost)}</span> },
            { key: "acc", header: t("Depreciated"), align: "right", hideOnMobile: true, render: (r) => <span className="num">{money(r.accumulated_depreciation)}<span className="block text-[11px] text-ink-400">{t("{amount}/month", { amount: money(r.monthly_depreciation) })}</span></span> },
            { key: "book", header: t("Book value"), align: "right", render: (r) => <span className="num font-semibold">{money(r.book_value)}</span> },
            { key: "status", header: t("Status"), render: (r) => <Badge tone={tone[r.status]}>{t(r.status === "active" ? "In use" : r.status === "disposed" ? "Disposed" : "Fully depreciated")}</Badge> },
            { key: "_a", header: "", align: "right", render: (r) => can("accounting.fixed_assets") && r.status !== "disposed" ? <Button size="sm" variant="ghost" onClick={() => setDisposing(r)}>{t("Dispose")}</Button> : null },
          ]}
          empty={{ title: t("No fixed assets registered") }} />
        <Pagination meta={list.data?.meta} onPage={setPage} />
      </Panel>
      <RegisterDialog open={registering} onClose={() => setRegistering(false)} />
      {disposing && <DisposeDialog asset={disposing} onClose={() => setDisposing(null)} />}
    </Guard>
  );
}
