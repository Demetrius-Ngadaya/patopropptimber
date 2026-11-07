"use client";

import { useEffect, useState } from "react";
import { MonitorSmartphone } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, money } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import { AccountNav } from "@/features/shop/components/AccountNav";
import { Badge, Button, ErrorState, Field, Input, Panel, Spinner, Switch } from "@/components/ui";
import { useT } from "@/lib/i18n";

type Profile = { code: string; name: string; phone: string; alt_phone: string | null; email: string | null; address: string | null; city: string | null; balance: string | number; sms_opt_in: boolean };
type Session = { id: number; device: string; ip_address: string | null; last_activity_at: string | null; created_at: string | null; current: boolean };

export default function AccountPage() {
  const t = useT();
  const { refresh, logout } = useAuth();
  const { settings } = usePublicSettings();
  const q = useApi<ApiResponse<{ profile: Profile }>>("shop/account");
  const sessions = useApi<ApiResponse<Session[]>>("auth/sessions");
  const [form, setForm] = useState({ name: "", phone: "", alt_phone: "", email: "", address: "", city: "", sms_opt_in: true });
  const [pw, setPw] = useState({ current_password: "", password: "", password_confirmation: "" });
  const p = q.data?.data.profile;

  useEffect(() => {
    if (p) setForm({ name: p.name, phone: p.phone ?? "", alt_phone: p.alt_phone ?? "", email: p.email ?? "", address: p.address ?? "", city: p.city ?? "", sms_opt_in: p.sms_opt_in });
  }, [p]);

  const save = useAction(() => api.put("shop/account", { ...form, alt_phone: form.alt_phone || null, email: form.email || null, address: form.address || null, city: form.city || null }), { invalidate: [["shop/account"]], onSuccess: () => refresh() });
  const change = useAction(() => api.post("auth/change-password", pw), { onSuccess: () => setPw({ current_password: "", password: "", password_confirmation: "" }) });
  const revoke = useAction((id: number) => api.del(`auth/sessions/${id}`), { invalidate: [["auth/sessions"]] });
  const errors = fieldErrors(save.error);
  const pwErrors = fieldErrors(change.error);
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value });

  return (
    <>
      <h1 className="mb-4 text-2xl font-semibold text-ink-950">{t("My account")}</h1>
      <AccountNav />
      {q.isLoading ? <Spinner /> : q.error ? <ErrorState message={q.error.message} onRetry={() => q.refetch()} /> : (
        <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
          <Panel title={t("My details")} actions={<span className="text-sm text-ink-500">{t("Customer no.")} <span className="num font-medium text-ink-800">{p?.code}</span></span>}>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2"><Field label={t("Full name")} required error={errors.name}>{(f) => <Input {...f} value={form.name} onChange={set("name")} />}</Field></div>
              <Field label={t("Phone number")} required error={errors.phone} hint={t("You use this to log in.")}>{(f) => <Input {...f} type="tel" value={form.phone} onChange={set("phone")} />}</Field>
              <Field label={t("Other phone")} error={errors.alt_phone}>{(f) => <Input {...f} type="tel" value={form.alt_phone} onChange={set("alt_phone")} />}</Field>
              <Field label={t("Email")} error={errors.email}>{(f) => <Input {...f} type="email" value={form.email} onChange={set("email")} />}</Field>
              <Field label={t("Town / city")} error={errors.city}>{(f) => <Input {...f} value={form.city} onChange={set("city")} />}</Field>
              <div className="sm:col-span-2"><Field label={t("Delivery address")} error={errors.address}>{(f) => <Input {...f} value={form.address} onChange={set("address")} />}</Field></div>
              <div className="sm:col-span-2"><Switch label={t("Send me SMS updates")} description={t("Order updates and receipts by SMS.")} checked={form.sms_opt_in} onChange={(v) => setForm({ ...form, sms_opt_in: v })} /></div>
            </div>
            <div className="mt-5 flex justify-end"><Button loading={save.isPending} onClick={() => save.mutate(undefined)}>{t("Save my details")}</Button></div>
          </Panel>
          <div className="space-y-5">
            {Number(p?.balance) > 0 && (
              <Panel><p className="text-sm text-ink-600">{t("Amount you owe the shop")}</p><p className="num mt-1 text-2xl font-semibold text-ember-600">{settings?.currency} {money(p?.balance)}</p></Panel>
            )}
            <Panel title={t("Change password")}>
              <div className="space-y-3">
                <Field label={t("Current password")} error={pwErrors.current_password}>{(f) => <Input {...f} type="password" autoComplete="current-password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} />}</Field>
                <Field label={t("New password")} error={pwErrors.password}>{(f) => <Input {...f} type="password" autoComplete="new-password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} />}</Field>
                <Field label={t("Repeat new password")}>{(f) => <Input {...f} type="password" autoComplete="new-password" value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} />}</Field>
                <Button variant="secondary" className="w-full" loading={change.isPending} disabled={!pw.current_password || !pw.password} onClick={() => change.mutate(undefined)}>{t("Change password")}</Button>
              </div>
            </Panel>
            <Panel title={t("Where you are logged in")}>
              <ul className="space-y-3">
                {sessions.data?.data.map((s) => (
                  <li key={s.id} className="flex items-start gap-3 text-sm">
                    <MonitorSmartphone className="mt-0.5 size-4 shrink-0 text-ink-400" />
                    <span className="min-w-0 flex-1"><span className="block truncate" title={s.device}>{s.device}</span><span className="text-xs text-ink-500">{s.ip_address} · {dateTime(s.last_activity_at ?? s.created_at)}</span></span>
                    {s.current ? <Badge tone="green">{t("This device")}</Badge> : <button type="button" className="text-xs font-medium text-ember-600 hover:underline" onClick={() => revoke.mutate(s.id)}>{t("Log out")}</button>}
                  </li>
                ))}
              </ul>
              <Button variant="ghost" className="mt-4 w-full text-ember-700" onClick={() => logout(t("You have logged out."))}>{t("Log out of this device")}</Button>
            </Panel>
          </div>
        </div>
      )}
    </>
  );
}
