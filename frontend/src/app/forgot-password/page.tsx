"use client";

import Link from "next/link";
import { useState } from "react";
import { api } from "@/lib/api";
import { useAction } from "@/lib/hooks";
import { Button, Field, Input } from "@/components/ui";
import { useT } from "@/lib/i18n";

export default function ForgotPasswordPage() {
  const t = useT();
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const send = useAction((v: { email: string }) => api.post("auth/forgot-password", v), { onSuccess: () => setSent(true) });

  return (
    <div className="flex min-h-dvh items-center justify-center px-5">
      <form
        className="w-full max-w-sm"
        onSubmit={(e) => {
          e.preventDefault();
          send.mutate({ email });
        }}
      >
        <h1 className="text-2xl font-semibold tracking-tight">{t("Reset your password")}</h1>
        <p className="mt-1 text-sm text-ink-500">
          {t("Enter the email on your account and we will send a reset link. Staff without an email can ask an administrator to reset their password.")}
        </p>
        {sent ? (
          <p className="mt-6 rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-700">{t("If that email has an account, a reset link is on its way. Check your inbox.")}</p>
        ) : (
          <>
            <Field label={t("Email")} required className="mt-6">
              {(p) => <Input {...p} type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" required />}
            </Field>
            <Button type="submit" className="mt-5 w-full" loading={send.isPending} disabled={!email}>{t("Send reset link")}</Button>
          </>
        )}
        <Link href="/login" className="mt-5 block text-sm font-medium text-brand-700 hover:underline">{t("Back to log in")}</Link>
      </form>
    </div>
  );
}
