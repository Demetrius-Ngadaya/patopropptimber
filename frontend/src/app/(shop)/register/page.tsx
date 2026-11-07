"use client";

import Link from "next/link";
import { Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { ApiError, authRequest } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fieldErrors } from "@/lib/hooks";
import { usePublicSettings } from "@/lib/public-settings";
import type { SessionUser } from "@/lib/types";
import { Button, Checkbox, Field, Input, Spinner } from "@/components/ui";
import { useT } from "@/lib/i18n";

function RegisterForm() {
  const t = useT();
  const router = useRouter();
  const params = useSearchParams();
  const { setUser, user } = useAuth();
  const { settings } = usePublicSettings();
  const [form, setForm] = useState({ name: "", phone: "", email: "", address: "", city: "", password: "", password_confirmation: "", accept_terms: false });
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const errors = fieldErrors(error);
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: k === "accept_terms" ? e.target.checked : e.target.value });

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const res = await authRequest<{ message: string; data: { user: SessionUser } }>("register", { ...form, email: form.email || null });
      setUser(res.data.user);
      toast.success(res.message);
      const next = params.get("next");
      router.replace(next && next.startsWith("/") && !next.startsWith("//") && !next.startsWith("/admin") ? next : "/");
    } catch (err) {
      setError(err);
      if (err instanceof ApiError && !Object.keys(err.errors).length) toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  if (settings && (!settings.shop_allow_registration || !settings.shop_enabled)) {
    return <div className="mx-auto max-w-md py-16 text-center"><h1 className="text-2xl font-semibold">{t("Registration is closed")}</h1><p className="mt-2 text-ink-600">{t("Please ask the shop to create an account for you.")}</p></div>;
  }
  if (user) {
    return <div className="mx-auto max-w-md py-16 text-center"><h1 className="text-2xl font-semibold">{t("You are already logged in")}</h1><p className="mt-2"><Link href="/" className="font-medium text-brand-700 hover:underline">{t("Continue shopping")}</Link></p></div>;
  }

  return (
    <form onSubmit={submit} noValidate className="mx-auto max-w-lg rounded-xl bg-surface p-6 ring-1 ring-ink-200/70 sm:p-8">
      <h1 className="text-2xl font-semibold tracking-tight text-ink-950">{t("Create your account")}</h1>
      <p className="mt-1 text-sm text-ink-500">{t("Order online and follow your orders. You log in with your phone number.")}</p>
      <div className="mt-6 grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><Field label={t("Full name")} required error={errors.name}>{(p) => <Input {...p} autoComplete="name" value={form.name} onChange={set("name")} />}</Field></div>
        <Field label={t("Phone number")} required error={errors.phone}>{(p) => <Input {...p} type="tel" autoComplete="tel" placeholder={t("07XX XXX XXX")} value={form.phone} onChange={set("phone")} />}</Field>
        <Field label={t("Email (optional)")} error={errors.email}>{(p) => <Input {...p} type="email" autoComplete="email" value={form.email} onChange={set("email")} />}</Field>
        <Field label={t("Address (optional)")} error={errors.address}>{(p) => <Input {...p} autoComplete="street-address" value={form.address} onChange={set("address")} />}</Field>
        <Field label={t("Town / city (optional)")} error={errors.city}>{(p) => <Input {...p} autoComplete="address-level2" value={form.city} onChange={set("city")} />}</Field>
        <Field label={t("Password")} required error={errors.password} hint={t("At least 8 characters with letters and numbers.")}>{(p) => <Input {...p} type="password" autoComplete="new-password" value={form.password} onChange={set("password")} />}</Field>
        <Field label={t("Repeat password")} required>{(p) => <Input {...p} type="password" autoComplete="new-password" value={form.password_confirmation} onChange={set("password_confirmation")} />}</Field>
      </div>
      <div className="mt-5">
        <Checkbox label={t("I agree that the shop may keep my details to handle my orders and contact me about them.")} checked={form.accept_terms} onChange={set("accept_terms")} />
        {errors.accept_terms && <p className="mt-1 text-sm text-ember-600">{errors.accept_terms}</p>}
      </div>
      <Button type="submit" size="lg" className="mt-6 w-full" loading={busy} disabled={!form.name || !form.phone || !form.password}>{t("Create account")}</Button>
      <p className="mt-4 text-center text-sm text-ink-600">{t("Already have an account?")} <Link href={`/login${params.get("next") ? `?next=${encodeURIComponent(params.get("next")!)}` : ""}`} className="font-medium text-brand-700 hover:underline">{t("Log in")}</Link></p>
    </form>
  );
}

export default function RegisterPage() {
  return <Suspense fallback={<Spinner variant="compact" />}><RegisterForm /></Suspense>;
}
