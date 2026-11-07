"use client";

import Link from "next/link";
import { Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { api } from "@/lib/api";
import { fieldErrors, useAction } from "@/lib/hooks";
import { Button, Field, Input } from "@/components/ui";
import { useT } from "@/lib/i18n";

function ResetForm() {
  const t = useT();
  const params = useSearchParams();
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const reset = useAction(
    () => api.post("auth/reset-password", { token: params.get("token"), email: params.get("email"), password, password_confirmation: confirm }),
    { onSuccess: () => router.replace("/login") },
  );
  const errors = fieldErrors(reset.error);

  return (
    <form
      className="w-full max-w-sm"
      onSubmit={(e) => {
        e.preventDefault();
        reset.mutate(undefined);
      }}
    >
      <h1 className="text-2xl font-semibold tracking-tight">{t("Choose a new password")}</h1>
      <p className="mt-1 text-sm text-ink-500">{t("At least 8 characters with letters and numbers.")}</p>
      <div className="mt-6 space-y-4">
        <Field label={t("New password")} required error={errors.password}>
          {(p) => <Input {...p} type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" />}
        </Field>
        <Field label={t("Repeat new password")} required>
          {(p) => <Input {...p} type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="new-password" />}
        </Field>
      </div>
      <Button type="submit" className="mt-5 w-full" loading={reset.isPending} disabled={!password || password !== confirm}>{t("Save new password")}</Button>
      <Link href="/login" className="mt-5 block text-sm font-medium text-brand-700 hover:underline">{t("Back to log in")}</Link>
    </form>
  );
}

export default function ResetPasswordPage() {
  return (
    <div className="flex min-h-dvh items-center justify-center px-5">
      <Suspense>
        <ResetForm />
      </Suspense>
    </div>
  );
}
