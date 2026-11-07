"use client";

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { MonitorSmartphone } from "lucide-react";
import { api, type ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime } from "@/lib/format";
import { fieldErrors, useAction, useApi } from "@/lib/hooks";
import type { SessionUser } from "@/lib/types";
import { Badge, Button, DescriptionList, Field, Input, PageHeader, Panel } from "@/components/ui";
import { useT } from "@/lib/i18n";
import { PreferencesPanel } from "@/features/auth/components/PreferencesPanel";
import { TwoFactorPanel } from "@/features/auth/components/TwoFactorPanel";

type Session = { id: number; device: string; ip_address: string | null; last_activity_at: string | null; current: boolean };

function ProfileInner() {
  const t = useT();
  const { user, setUser, refresh } = useAuth();
  const params = useSearchParams();
  const first = params.get("first") === "1" || user?.must_change_password;
  const security = params.get("security") === "1";

  useEffect(() => {
    if (security) document.getElementById("security")?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [security]);
  const [profile, setProfile] = useState({ name: "", email: "", phone: "" });
  const [pw, setPw] = useState({ current_password: "", password: "", password_confirmation: "" });

  useEffect(() => {
    if (user) setProfile({ name: user.name, email: user.email ?? "", phone: user.phone ?? "" });
  }, [user]);

  const sessions = useApi<ApiResponse<Session[]>>("auth/sessions");
  const saveProfile = useAction((body: typeof profile) => api.put<ApiResponse<SessionUser>>("auth/profile", body), { onSuccess: (r) => setUser(r.data) });
  const changePassword = useAction((body: typeof pw) => api.post("auth/change-password", body), {
    onSuccess: () => {
      setPw({ current_password: "", password: "", password_confirmation: "" });
      refresh();
      sessions.refetch();
    },
  });
  const revoke = useAction((id: number) => api.del(`auth/sessions/${id}`), { invalidate: [["auth/sessions"]] });
  const profileErrors = fieldErrors(saveProfile.error);
  const pwErrors = fieldErrors(changePassword.error);

  if (!user) return null;

  return (
    <>
      <PageHeader title={t("My profile")} description={t("Your details, password, two-step login, language and the devices you are logged in on.")} />
      {first && (
        <div role="alert" className="mb-5 rounded-[var(--radius-panel)] bg-saffron-50 px-4 py-3 text-sm text-saffron-700 ring-1 ring-saffron-100">
          {t("Please set your own password before you continue. The password you were given is temporary.")}
        </div>
      )}
      <div className="grid gap-5 lg:grid-cols-2">
        <Panel title={t("Details")}>
          <DescriptionList items={[["Username", user.username], ["Role", user.role?.name], ["Branch", user.all_branches ? t("All branches") : user.branch?.name], ["Last login", dateTime(user.last_login_at)]]} />
          <form
            className="mt-5 space-y-4 border-t border-ink-100 pt-5"
            onSubmit={(e) => {
              e.preventDefault();
              saveProfile.mutate(profile);
            }}
          >
            <Field label={t("Full name")} required error={profileErrors.name}>
              {(p) => <Input {...p} value={profile.name} onChange={(e) => setProfile({ ...profile, name: e.target.value })} />}
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t("Email")} error={profileErrors.email} hint={t("Needed for password reset emails.")}>
                {(p) => <Input {...p} type="email" value={profile.email} onChange={(e) => setProfile({ ...profile, email: e.target.value })} />}
              </Field>
              <Field label={t("Phone")} error={profileErrors.phone}>
                {(p) => <Input {...p} value={profile.phone} onChange={(e) => setProfile({ ...profile, phone: e.target.value })} />}
              </Field>
            </div>
            <Button type="submit" loading={saveProfile.isPending}>{t("Save details")}</Button>
          </form>
        </Panel>

        <Panel title={t("Change password")} className="scroll-mt-20" >
          <form
            id="password"
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault();
              changePassword.mutate(pw);
            }}
          >
            <Field label={t("Current password")} required error={pwErrors.current_password}>
              {(p) => <Input {...p} type="password" autoComplete="current-password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} />}
            </Field>
            <Field label={t("New password")} required error={pwErrors.password} hint={t("At least 8 characters with letters and numbers.")}>
              {(p) => <Input {...p} type="password" autoComplete="new-password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} />}
            </Field>
            <Field label={t("Repeat new password")} required error={pw.password_confirmation && pw.password !== pw.password_confirmation ? t("The passwords do not match.") : undefined}>
              {(p) => <Input {...p} type="password" autoComplete="new-password" value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} />}
            </Field>
            <Button type="submit" loading={changePassword.isPending} disabled={!pw.current_password || !pw.password || pw.password !== pw.password_confirmation}>
              {t("Change password")}
            </Button>
            <p className="text-[12.5px] text-ink-500">{t("Changing your password logs you out on every other device.")}</p>
          </form>
        </Panel>

        <TwoFactorPanel highlight={security} />
        <PreferencesPanel />

        <Panel title={t("Where you're logged in")} className="lg:col-span-2" padded={false}>
          <ul className="divide-y divide-ink-100">
            {(sessions.data?.data ?? []).map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div className="flex min-w-0 items-center gap-3">
                  <MonitorSmartphone className="size-5 shrink-0 text-ink-400" aria-hidden />
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink-800">{s.device}</p>
                    <p className="text-xs text-ink-500">{t("{ip_address} · last active {last_activity_at}", { ip_address: s.ip_address, last_activity_at: dateTime(s.last_activity_at) })}</p>
                  </div>
                </div>
                {s.current ? <Badge tone="green">{t("This device")}</Badge> : (
                  <Button size="sm" variant="secondary" loading={revoke.isPending && revoke.variables === s.id} onClick={() => revoke.mutate(s.id)}>{t("Log out device")}</Button>
                )}
              </li>
            ))}
          </ul>
        </Panel>
      </div>
    </>
  );
}

export default function ProfilePage() {
  return (
    <Suspense>
      <ProfileInner />
    </Suspense>
  );
}
