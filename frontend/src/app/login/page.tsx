"use client";

import Link from "next/link";
import { Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ArrowLeft, Eye, EyeOff, LockKeyhole, ShieldCheck } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError, authRequest } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { LOCALE_COOKIE, useLocale, useT } from "@/lib/i18n";
import { usePublicSettings } from "@/lib/public-settings";
import { THEME_COOKIE, useTheme } from "@/lib/theme/ThemeProvider";
import type { SessionUser } from "@/lib/types";
import { Button, Field, Input } from "@/components/ui";
import { PatoProMark, PatoProWordmark } from "@/components/brand/PatoProLogo";
import { BRAND } from "@/lib/brand";
import { LanguageSwitch, ThemeSwitch } from "@/components/layout/PreferenceSwitches";
import { LoginSlider } from "@/features/auth/components/LoginSlider";
import { AppDownloadCard } from "@/features/mobile-app/AppDownload";

type LoginResponse = { message: string; data: { user?: SessionUser; two_factor_required?: boolean; challenge?: string } };

function LoginForm() {
  const t = useT();
  const router = useRouter();
  const params = useSearchParams();
  const { setUser } = useAuth();
  const { settings } = usePublicSettings();
  const { locale, setLocale } = useLocale();
  const { theme, setTheme } = useTheme();
  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [show, setShow] = useState(false);
  const [challenge, setChallenge] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const finish = (user: SessionUser, message: string) => {
    setUser(user);
    // A language / theme chosen on this device wins and is saved to the account;
    // otherwise the account's saved choice follows the user to this device.
    const chosenHere = (name: string) => new RegExp(`(?:^|; )${name}=`).test(document.cookie);
    const patch: { locale?: string; theme?: string } = {};
    if (chosenHere(LOCALE_COOKIE)) { if (user.locale !== locale) patch.locale = locale; } else if (user.locale && user.locale !== locale) setLocale(user.locale);
    if (chosenHere(THEME_COOKIE)) { if (user.theme !== theme) patch.theme = theme; } else if (user.theme && user.theme !== theme) setTheme(user.theme);
    if (Object.keys(patch).length) void api.put("auth/preferences", patch).catch(() => undefined);
    toast.success(message);
    const next = params.get("next");
    const safeNext = next && next.startsWith("/") && !next.startsWith("//") ? next : null;
    if (user.type === "staff") {
      router.replace(user.must_change_password ? "/admin/profile?first=1" : safeNext?.startsWith("/admin") ? safeNext : "/admin");
    } else {
      router.replace(safeNext && !safeNext.startsWith("/admin") ? safeNext : "/account");
    }
  };

  const run = async (fn: () => Promise<void>) => {
    setError(null);
    setBusy(true);
    try {
      await fn();
    } catch (err) {
      setError(err instanceof ApiError ? t(err.message) : t("Login failed. Please try again."));
    } finally {
      setBusy(false);
    }
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    void run(async () => {
      const res = await authRequest<LoginResponse>("login", { login, password });
      if (res.data.two_factor_required && res.data.challenge) {
        setChallenge(res.data.challenge);
        setPassword("");
        return;
      }
      if (res.data.user) finish(res.data.user, res.message);
    });
  };

  const verify = (e: React.FormEvent) => {
    e.preventDefault();
    void run(async () => {
      const res = await authRequest<LoginResponse>("two-factor", { challenge, code });
      if (res.data.user) finish(res.data.user, res.message);
    });
  };

  return (
    <div className="grid min-h-dvh lg:grid-cols-[1.15fr_1fr]">
      {/* Left: pictures managed by the administrator (Administration > Login pictures). */}
      <div className="keep-light relative hidden lg:block">
        <LoginSlider />
      </div>

      {/* Right: the logo in a circle, centred above the form. */}
      <div className="relative flex flex-col bg-surface">
        <div className="flex justify-end gap-1 p-4">
          <LanguageSwitch />
          <ThemeSwitch />
        </div>
        <div className="flex flex-1 items-center justify-center px-5 pb-12">
          <div className="w-full max-w-sm">
            {/* The product brand: logo, PatoPro, by SHEZONI. */}
            <div className="mb-7 flex flex-col items-center text-center">
              <PatoProMark className="size-16 rounded-[18px] shadow-md" />
              <PatoProWordmark className="mt-4 text-[34px]" />
              <p className="mt-1.5 text-[13px] font-medium tracking-wide text-ink-500">by {BRAND.company}</p>
            </div>

            {!challenge ? (
              <form onSubmit={submit} noValidate>
                <h1 className="text-center text-xl font-semibold tracking-tight text-ink-950">{t("Welcome back! Sign in to continue.")}</h1>

                {params.get("next") && !error && <p className="mt-5 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-700">{t("Please log in to continue.")}</p>}
                {error && <p role="alert" className="mt-5 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{error}</p>}

                <div className="mt-6 space-y-4">
                  <Field label={t("Username, email or phone")} required>
                    {(p) => <Input {...p} value={login} onChange={(e) => setLogin(e.target.value)} autoComplete="username" autoFocus required />}
                  </Field>
                  <Field label={t("Password")} required>
                    {(p) => (
                      <div className="relative">
                        <Input {...p} type={show ? "text" : "password"} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required className="pr-10" />
                        <button type="button" onClick={() => setShow((s) => !s)} className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-ink-500 hover:text-ink-800" aria-label={show ? t("Hide password") : t("Show password")}>
                          {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </button>
                      </div>
                    )}
                  </Field>
                </div>

                <Button type="submit" size="lg" className="mt-6 w-full" loading={busy} disabled={!login || !password} icon={<LockKeyhole className="size-4" />}>
                  {t("Log in")}
                </Button>

                <div className="mt-5 flex items-center justify-between text-sm">
                  <Link href="/forgot-password" className="font-medium text-brand-700 hover:underline">{t("Forgot password?")}</Link>
                  {settings?.shop_allow_registration !== false && <Link href="/register" className="font-medium text-ink-600 hover:underline">{t("Create a customer account")}</Link>}
                </div>

                <AppDownloadCard className="mt-6" />
              </form>
            ) : (
              <form onSubmit={verify} noValidate>
                <div className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-brand-50 text-brand-700"><ShieldCheck className="size-6" /></div>
                <h1 className="text-center text-2xl font-semibold tracking-tight text-ink-950">{t("Two-step verification")}</h1>
                <p className="mt-1 text-center text-sm text-ink-500">{t("Enter the 6-digit code from your authenticator app, or one of your recovery codes.")}</p>
                {error && <p role="alert" className="mt-5 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700">{error}</p>}
                <Field label={t("Code")} required className="mt-6">
                  {(p) => (
                    <Input {...p} value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" autoFocus maxLength={20} className="num text-center text-lg tracking-[0.4em]" />
                  )}
                </Field>
                <Button type="submit" size="lg" className="mt-6 w-full" loading={busy} disabled={code.trim().length < 6}>{t("Verify and log in")}</Button>
                <button type="button" onClick={() => { setChallenge(null); setCode(""); setError(null); }} className="mx-auto mt-5 flex items-center gap-1 text-sm font-medium text-ink-600 hover:underline">
                  <ArrowLeft className="size-4" /> {t("Back to login")}
                </button>
              </form>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}
