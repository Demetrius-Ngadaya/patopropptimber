"use client";

import { useState } from "react";
import { CheckCircle2, Copy, PlugZap, XCircle } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { money } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Button, Panel, Spinner } from "@/components/ui";
import type { OnlinePaymentsStatus } from "./types";

function Row({ ok, label, hint }: { ok: boolean; label: string; hint?: string }) {
  return (
    <li className="flex gap-2">
      {ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-brand-600" /> : <XCircle className="mt-0.5 size-4 shrink-0 text-ember-600" />}
      <span>
        <span className="font-medium text-ink-900">{label}</span>
        {hint && <span className="block text-xs text-ink-500">{hint}</span>}
      </span>
    </li>
  );
}

/** Settings → Online payments: is Snippe set up on the server, where it sends results, and a connection test. */
export function OnlinePaymentsStatusPanel() {
  const t = useT();
  const q = useApi<ApiResponse<OnlinePaymentsStatus>>("admin/online-payments/status");
  const [test, setTest] = useState<OnlinePaymentsStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const s = test ?? q.data?.data;

  const runTest = async () => {
    setBusy(true);
    try {
      const res = await api.get<ApiResponse<OnlinePaymentsStatus>>("admin/online-payments/status", { test: 1 });
      setTest(res.data);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : t("Could not test the connection."));
    } finally {
      setBusy(false);
    }
  };

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast.success(t("Copied."));
    } catch {
      toast.error(t("Copy it by hand."));
    }
  };

  if (!s) return <Panel title={t("Snippe connection")}><Spinner variant="compact" /></Panel>;


  return (
    <Panel title={t("Snippe connection")}>
      <ul className="space-y-3 text-sm">
        <Row ok={s.configured} label={s.configured ? t("API key set on the server") : t("API key missing")} hint={s.configured ? undefined : t("Add SNIPPE_API_KEY to the backend .env file (scopes collection:read and collection:create).")} />
        <Row ok={s.webhook_secret} label={s.webhook_secret ? t("Webhook signing key set") : t("Webhook signing key missing")} hint={s.webhook_secret ? undefined : t("Add SNIPPE_WEBHOOK_SECRET from Snippe → Settings → Webhook Secret. Without it, results are only found by asking Snippe.")} />
        <Row ok={s.pos} label={s.pos ? t("On at the till") : t("Off at the till")} />
        <Row ok={s.shop} label={s.shop ? t("On in the online shop") : t("Off in the online shop")} />
      </ul>

      <div className="mt-4 border-t border-ink-100 pt-4">
        <p className="text-xs font-medium uppercase tracking-wide text-ink-500">{t("Webhook address")}</p>
        <div className="mt-1 flex items-start gap-1">
          <code className="min-w-0 flex-1 break-all rounded bg-ink-50 px-2 py-1 text-xs text-ink-800">{s.webhook_url}</code>
          <Button size="sm" variant="ghost" aria-label={t("Copy")} onClick={() => copy(s.webhook_url)}><Copy className="size-4" /></Button>
        </div>
        {!s.webhook_https && <p className="mt-1 text-xs text-saffron-700">{t("Not HTTPS: Snippe cannot send results here. Payments are still confirmed by checking with Snippe while the till or the customer waits.")}</p>}
      </div>

      <div className="mt-4 border-t border-ink-100 pt-4">
        <Button variant="secondary" size="sm" icon={<PlugZap className="size-4" />} loading={busy} disabled={!s.configured} onClick={runTest}>{t("Test the connection")}</Button>
        {s.test && (
          <p className={s.test.ok ? "mt-2 text-sm text-brand-700" : "mt-2 text-sm text-ember-700"} role="status">
            {s.test.message}
            {s.balance && <span className="block text-ink-700">{t("Balance at Snippe: {amount}", { amount: money(s.balance.available) })}</span>}
          </p>
        )}
      </div>
      <p className="mt-4 text-xs text-ink-500">{t("Smallest payment: {amount}. Fees are deducted by Snippe and shown on each payment.", { amount: money(s.minimum) })}</p>
    </Panel>
  );
}
