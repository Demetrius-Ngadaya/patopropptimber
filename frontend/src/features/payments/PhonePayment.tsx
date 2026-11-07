"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { CheckCircle2, Loader2, RotateCw, Smartphone, XCircle } from "lucide-react";
import { api, ApiError, type ApiResponse } from "@/lib/api";
import { money } from "@/lib/format";
import { useT } from "@/lib/i18n";
import { Button, Input } from "@/components/ui";
import { isTzMobile, type OnlinePayment } from "./types";

type Props = {
  amount: number;
  minimum: number;
  defaultPhone?: string | null;
  payerName?: string | null;
  customerId?: number | null;
  /** Called with the payment once the gateway confirms it (null when it is started again). */
  onPaid: (payment: OnlinePayment | null) => void;
  /** True while a request is out, so the amount is not changed under it. */
  onWaiting?: (waiting: boolean) => void;
};

const POLL_MS = 3000;

/**
 * Payment by phone at the till: the cashier enters the customer's number, a
 * USSD push goes to the phone, and the line is accepted only once the
 * customer has entered their PIN and the gateway confirms it.
 */
export function PhonePayment({ amount, minimum, defaultPhone, payerName, customerId, onPaid, onWaiting }: Props) {
  const t = useT();
  const [phone, setPhone] = useState(defaultPhone ?? "");
  const [payment, setPayment] = useState<OnlinePayment | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const reported = useRef<number | null>(null);

  const stop = () => {
    if (timer.current) clearInterval(timer.current);
    timer.current = null;
  };
  useEffect(() => stop, []);
  const waiting = payment?.status === "pending";
  useEffect(() => { onWaiting?.(waiting); }, [waiting, onWaiting]);

  const check = useCallback(async (id: number, force = false) => {
    try {
      const res = await api.get<ApiResponse<OnlinePayment>>(`admin/online-payments/${id}`, force ? { force: 1 } : undefined);
      setPayment(res.data);
      if (res.data.is_final) stop();
      if (res.data.status === "completed" && reported.current !== res.data.id) {
        reported.current = res.data.id;
        onPaid(res.data);
      }
    } catch {
      // Keep waiting: a lost connection at the till should not drop a payment.
    }
  }, [onPaid]);

  const send = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api.post<ApiResponse<OnlinePayment>>("admin/online-payments", { amount, phone, payer_name: payerName || undefined, customer_id: customerId || undefined });
      setPayment(res.data);
      stop();
      timer.current = setInterval(() => check(res.data.id), POLL_MS);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t("The request could not be sent."));
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    if (!payment) return;
    setBusy(true);
    try {
      await api.post(`admin/online-payments/${payment.id}/resend`);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : null);
    } finally {
      setBusy(false);
    }
  };

  const startAgain = async () => {
    stop();
    if (payment?.status === "pending") {
      try { await api.post(`admin/online-payments/${payment.id}/cancel`); } catch { /* already finished */ }
    }
    setPayment(null);
    reported.current = null;
    onPaid(null);
  };

  const whole = Math.abs(amount - Math.round(amount)) < 0.001;
  const amountProblem = amount <= 0 ? null : !whole ? t("Mobile money takes whole shillings only.") : amount < minimum ? t("The smallest amount is {amount}.", { amount: money(minimum) }) : null;

  if (payment?.status === "completed") {
    return (
      <div className="col-span-full flex items-center gap-2 rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-800" role="status">
        <CheckCircle2 className="size-5 shrink-0" />
        <span className="min-w-0 flex-1">
          {t("Paid {amount} by {provider}", { amount: money(payment.amount), provider: payment.provider_name ?? t("mobile money") })}
          <span className="block text-xs text-brand-700">{payment.external_reference ?? payment.number} · {payment.phone}</span>
        </span>
      </div>
    );
  }

  if (payment?.status === "pending") {
    return (
      <div className="col-span-full space-y-2 rounded-md bg-saffron-50 px-3 py-2 text-sm text-saffron-900" role="status" aria-live="polite">
        <p className="flex items-center gap-2 font-medium"><Loader2 className="size-4 animate-spin" /> {t("Waiting for the customer to enter their PIN on {phone}…", { phone: payment.phone })}</p>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" icon={<RotateCw className="size-4" />} loading={busy} onClick={resend}>{t("Send again")}</Button>
          <Button size="sm" variant="ghost" onClick={() => check(payment.id, true)}>{t("Check now")}</Button>
          <Button size="sm" variant="ghost" onClick={startAgain}>{t("Stop waiting")}</Button>
        </div>
        {error && <p className="text-xs text-ember-700">{error}</p>}
      </div>
    );
  }

  return (
    <div className="col-span-full space-y-2">
      {payment && (
        <p className="flex items-center gap-2 rounded-md bg-ember-50 px-3 py-2 text-sm text-ember-700" role="alert">
          <XCircle className="size-4 shrink-0" /> {payment.failure_reason ?? t("The payment did not go through.")}
        </p>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <Input aria-label={t("Customer's mobile money number")} type="tel" inputMode="tel" placeholder="0712 345 678" className="min-w-0 flex-1" value={phone} onChange={(e) => setPhone(e.target.value)} />
        <Button icon={<Smartphone className="size-4" />} loading={busy} disabled={!isTzMobile(phone) || Boolean(amountProblem) || amount <= 0} onClick={send}>
          {payment ? t("Try again") : t("Send to phone")}
        </Button>
      </div>
      <p className="text-xs text-ink-500">{amountProblem ?? t("The customer gets a prompt on their phone (M-Pesa, Airtel Money, Mixx by Yas, HaloPesa) and enters their PIN.")}</p>
      {error && <p className="text-xs text-ember-700" role="alert">{error}</p>}
    </div>
  );
}
