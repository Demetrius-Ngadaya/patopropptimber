"use client";

import { useEffect, useRef, useState } from "react";
import { CheckCircle2, Loader2, Smartphone, XCircle } from "lucide-react";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { money } from "@/lib/format";
import { useApi } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { Button, Input } from "@/components/ui";
import { isTzMobile, type OnlinePaymentStatus } from "@/features/payments/types";

type State = {
  payment: { number: string; status: OnlinePaymentStatus; amount: string; phone: string; provider_name: string | null; failure_reason: string | null } | null;
  paid_online: number;
  total: number;
  to_pay: number;
  can_pay: boolean;
};

/**
 * The customer's order page: pay by phone (or try again), and follow the
 * payment while they enter their PIN.
 */
export function OrderPayment({ number, currency, defaultPhone, onPaid }: { number: string; currency: string; defaultPhone?: string; onPaid: () => void }) {
  const t = useT();
  const path = `shop/account/orders/${encodeURIComponent(number)}/payment`;
  const q = useApi<ApiResponse<State>>(path);
  const s = q.data?.data;
  const waiting = s?.payment?.status === "pending";
  const refetch = q.refetch;
  const [phone, setPhone] = useState(defaultPhone ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { if (defaultPhone) setPhone((v) => v || defaultPhone); }, [defaultPhone]);

  // While the customer is entering their PIN, check every few seconds.
  useEffect(() => {
    if (!waiting) return;
    const id = setInterval(() => refetch(), 3000);
    return () => clearInterval(id);
  }, [waiting, refetch]);

  // When a payment we were waiting for goes through, the page shows the new paid amount.
  const status = s?.payment?.status ?? null;
  const previous = useRef(status);
  useEffect(() => {
    if (previous.current === "pending" && status === "completed") onPaid();
    previous.current = status;
  }, [status, onPaid]);

  if (!s || (!s.can_pay && !s.payment && s.paid_online <= 0)) return null;

  const pay = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.post(`shop/account/orders/${encodeURIComponent(number)}/pay`, { phone });
      await q.refetch();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t("The payment request could not be sent."));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="rounded-lg bg-surface p-4 ring-1 ring-ink-200/70" aria-live="polite">
      <h2 className="mb-2 flex items-center gap-2 font-semibold"><Smartphone className="size-4 text-brand-700" /> {t("Pay with mobile money")}</h2>
      {s.paid_online > 0 && (
        <p className="mb-2 flex items-start gap-2 rounded-md bg-brand-50 px-3 py-2 text-brand-800">
          <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
          <span>{s.to_pay > 0 ? t("{amount} paid. {rest} still to pay.", { amount: `${currency} ${money(s.paid_online)}`, rest: `${currency} ${money(s.to_pay)}` }) : t("Paid in full: {amount}. Thank you!", { amount: `${currency} ${money(s.paid_online)}` })}</span>
        </p>
      )}
      {waiting && (
        <div className="rounded-md bg-saffron-50 px-3 py-2 text-saffron-900">
          <p className="flex items-center gap-2 font-medium"><Loader2 className="size-4 animate-spin" /> {t("Check your phone {phone}", { phone: s.payment!.phone })}</p>
          <p className="mt-1 text-[13px]">{t("A prompt asks you to pay {amount}. Enter your PIN to confirm. This page updates by itself.", { amount: `${currency} ${money(s.payment!.amount)}` })}</p>
        </div>
      )}
      {!waiting && s.payment && s.payment.status !== "completed" && (
        <p className="mb-2 flex items-start gap-2 rounded-md bg-ember-50 px-3 py-2 text-ember-700" role="alert">
          <XCircle className="mt-0.5 size-4 shrink-0" /> {s.payment.failure_reason ?? t("The payment did not go through.")}
        </p>
      )}
      {!waiting && s.can_pay && (
        <div className="mt-2 space-y-2">
          <p className="text-ink-600">{t("Pay {amount} now from M-Pesa, Airtel Money, Mixx by Yas or HaloPesa.", { amount: `${currency} ${money(s.to_pay)}` })}</p>
          <div className="flex gap-2">
            <Input aria-label={t("Mobile money number")} type="tel" inputMode="tel" placeholder="0712 345 678" value={phone} onChange={(e) => setPhone(e.target.value)} className="min-w-0 flex-1" />
            <Button loading={busy} disabled={!isTzMobile(phone)} onClick={pay}>{s.payment ? t("Try again") : t("Pay now")}</Button>
          </div>
          <p className="text-xs text-ink-500">{t("Or pay at the shop as usual.")}</p>
        </div>
      )}
      {error && <p className="mt-2 text-sm text-ember-700" role="alert">{error}</p>}
    </div>
  );
}
